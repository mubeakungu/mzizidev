"""
Casino Game Routes - Self-contained game server for the self-hosted
provably-fair games (Aviator, Dice, Mines, Plinko, Roulette, Slots).

Wallet debits/credits go through the real Wallet + Transaction models
(app/models/wallet.py) — the same ledger your M-Pesa deposit flow writes
to — so game balance and deposit balance never diverge.
"""

from flask import Blueprint, request, jsonify
from flask_login import login_required, current_user
from decimal import Decimal
from datetime import datetime

from app.models.casino import Game, CasinoRound
from app.models.wallet import Wallet, Transaction  # noqa: F401 (Wallet kept for typing/clarity)
from app.extensions import db
from app.game_engine import GameEngine, PayoutCalculator  # noqa: F401

casino_games_bp = Blueprint("casino_games", __name__, url_prefix="/api/casino")


@casino_games_bp.route("/init-round", methods=["POST"])
@login_required
def init_round():
    """
    Initialize a game round:
    - Validate stake
    - Reserve funds (debit wallet, log a 'stake' transaction)
    - Generate server seed & round ID
    """
    data = request.get_json()
    game_id = data.get("game_id")
    stake = Decimal(str(data.get("stake", 0)))
    client_seed = data.get("client_seed", "")

    if stake <= 0 or stake > Decimal("10000"):
        return jsonify({"error": "Invalid stake amount"}), 400

    game = Game.query.get(game_id)
    if not game:
        return jsonify({"error": "Game not found"}), 404

    wallet = current_user.wallet
    if not wallet or wallet.balance < stake:
        return jsonify({"error": "Insufficient balance"}), 402

    round_id = GameEngine.generate_round_id()
    server_seed = GameEngine.generate_server_seed()

    # Debit stake
    wallet.balance -= stake
    stake_txn = Transaction(
        wallet_id=wallet.id,
        type="stake",
        amount=stake,
        balance_after=wallet.balance,
        reference=f"casino:{round_id}:stake",
        status="completed",
    )
    db.session.add(stake_txn)

    casino_round = CasinoRound(
        user_id=current_user.id,
        game_id=game_id,
        stake=stake,
        status="pending",
        provider_round_id=round_id,
        provider_result={
            "server_seed": server_seed,
            "client_seed": client_seed,
            "round_id": round_id,
            "timestamp": datetime.utcnow().isoformat(),
        },
    )
    db.session.add(casino_round)
    db.session.commit()

    return jsonify({
        "round_id": round_id,
        "server_seed": server_seed,
        "client_seed": client_seed,
        "nonce": 1,
        "balance": float(wallet.balance),
    }), 200


@casino_games_bp.route("/settle-round", methods=["POST"])
@login_required
def settle_round():
    """
    Process game outcome and settle round.

    SECURITY: for dice/european-roulette/slots, the outcome and payout are
    recomputed here from the round's own server_seed (generated at
    init-round, before the stake was placed) — never taken from the
    client. The client only supplies its bet choice (bet_type / bets),
    which affects payout math but not the underlying RNG result. This
    closes an exploit where a modified client could submit an arbitrary
    winning result and payout (previously bounded only by a 100x-stake
    cap).

    Mines is NOT yet covered by full server-side verification — see the
    comment in that branch below.
    """
    data = request.get_json()
    round_id = data.get("round_id")
    game_id = data.get("game_id")
    client_game_result = data.get("game_result", {}) or {}

    casino_round = CasinoRound.query.filter_by(
        provider_round_id=round_id,
        user_id=current_user.id,
        status="pending",
    ).first_or_404()

    game = Game.query.get(game_id)
    if not game or game.id != casino_round.game_id:
        return jsonify({"error": "Game mismatch"}), 400

    wallet = current_user.wallet
    stake = casino_round.stake
    seeds = casino_round.provider_result or {}
    server_seed = seeds.get("server_seed")
    client_seed = seeds.get("client_seed") or ""

    canonical_result = {}

    if game.slug == "dice":
        dice_roll = GameEngine.get_dice_roll(server_seed, client_seed, nonce=1)
        bet_type = client_game_result.get("bet_type")
        won, payout = PayoutCalculator.calculate_dice_payout(bet_type, dice_roll, stake)
        canonical_result = {"dice_roll": dice_roll, "bet_type": bet_type, "won": won}

    elif game.slug == "european-roulette":
        winning_number = GameEngine.get_roulette_spin(server_seed, client_seed, nonce=1)
        bets = client_game_result.get("bets", {})
        payout = PayoutCalculator.calculate_roulette_payout(winning_number, bets)
        canonical_result = {"winning_number": winning_number, "bets": bets}

    elif game.slug == "slots":
        reels = GameEngine.get_slot_reels(server_seed, client_seed, nonce=1, num_reels=5)
        payout, win_type = PayoutCalculator.calculate_slot_win(reels, stake)
        canonical_result = {"reels": reels, "win_type": win_type}

    elif game.slug == "mines":
        # INTERIM MITIGATION ONLY. The client currently reports a
        # tiles-revealed count and multiplier, not the actual tile
        # indices it revealed in order, so we can't yet recompute the
        # true grid-vs-path outcome here — a modified client can still
        # claim a false (but plausible) tiles_revealed count. This
        # clamps payout to what that claimed count could legitimately
        # be worth, closing the "arbitrary payout up to 100x" hole but
        # not full path-cheating. Full fix: store the server-generated
        # grid at init-round, add a reveal-tile endpoint that checks
        # each pick server-side and rejects/records it, and settle from
        # that recorded state instead of a client-reported count.
        tiles_revealed = int(client_game_result.get("tiles_revealed", 0) or 0)
        difficulty = int(client_game_result.get("difficulty", 1) or 1)
        multiplier = PayoutCalculator.calculate_mines_multiplier(tiles_revealed, difficulty)
        payout = stake * multiplier
        canonical_result = {"tiles_revealed": tiles_revealed, "difficulty": difficulty}

    else:
        # Real-time/provider games settle through their own blueprints;
        # this endpoint shouldn't be reached for them, but fall back to
        # the client-submitted payout (still bounded by the 100x cap
        # below) rather than failing the request outright.
        payout = Decimal(str(data.get("payout", 0)))
        canonical_result = client_game_result

    payout = payout.quantize(Decimal("0.01"))

    # Payout can't exceed 100x stake — defense in depth even though the
    # branches above now compute payout server-side.
    max_payout = stake * Decimal("100")
    if payout < 0 or payout > max_payout:
        casino_round.status = "void"
        casino_round.payout = Decimal("0.00")

        wallet.balance += stake
        reversal_txn = Transaction(
            wallet_id=wallet.id,
            type="reversal",
            amount=stake,
            balance_after=wallet.balance,
            reference=f"casino:{round_id}:reversal",
            status="completed",
        )
        db.session.add(reversal_txn)
        db.session.commit()

        return jsonify({"error": "Invalid payout amount"}), 400

    casino_round.status = "settled"
    casino_round.payout = payout
    casino_round.settled_at = datetime.utcnow()

    result_data = dict(casino_round.provider_result or {})
    result_data["game_result"] = canonical_result
    result_data["payout"] = float(payout)
    casino_round.provider_result = result_data

    net_result = payout - stake

    if payout > 0:
        wallet.balance += payout
        payout_txn = Transaction(
            wallet_id=wallet.id,
            type="payout",
            amount=payout,
            balance_after=wallet.balance,
            reference=f"casino:{round_id}:payout",
            status="completed",
        )
        db.session.add(payout_txn)

    db.session.add(casino_round)
    db.session.commit()

    return jsonify({
        "status": "settled",
        "payout": float(payout),
        "net_result": float(net_result),
        "balance": float(wallet.balance),
        "round_id": round_id,
        "result": canonical_result,
    }), 200


@casino_games_bp.route("/get-balance", methods=["GET"])
@login_required
def get_balance():
    """Get current balance."""
    wallet = current_user.wallet
    return jsonify({
        "balance": float(wallet.balance) if wallet else 0,
        "currency": wallet.currency if wallet else "KES",
    }), 200


@casino_games_bp.route("/game-seeds/<round_id>", methods=["GET"])
@login_required
def get_game_seeds(round_id):
    """Get seeds for verification (provably fair)."""
    casino_round = CasinoRound.query.filter_by(
        provider_round_id=round_id,
        user_id=current_user.id,
    ).first_or_404()

    result_data = casino_round.provider_result or {}

    return jsonify({
        "round_id": round_id,
        "server_seed": result_data.get("server_seed"),
        "client_seed": result_data.get("client_seed"),
        "game_result": result_data.get("game_result"),
        "payout": result_data.get("payout"),
        "stake": float(casino_round.stake),
    }), 200


@casino_games_bp.route("/round-history", methods=["GET"])
@login_required
def round_history():
    """Get user's recent round history."""
    limit = request.args.get("limit", 20, type=int)
    offset = request.args.get("offset", 0, type=int)

    rounds = CasinoRound.query.filter_by(
        user_id=current_user.id,
        status="settled",
    ).order_by(CasinoRound.created_at.desc()).limit(limit).offset(offset).all()

    # CasinoRound has a raw game_id FK, no ORM relationship to Game (see
    # app/models/casino.py), so batch-fetch names/slugs instead of an
    # N+1 round_obj.game.* access pattern.
    game_ids = {r.game_id for r in rounds}
    games_by_id = {g.id: g for g in Game.query.filter(Game.id.in_(game_ids)).all()}

    history = []
    for round_obj in rounds:
        game = games_by_id.get(round_obj.game_id)
        history.append({
            "round_id": round_obj.provider_round_id,
            "game_name": game.name if game else "Unknown",
            "game_slug": game.slug if game else "unknown",
            "stake": float(round_obj.stake),
            "payout": float(round_obj.payout),
            "net": float(round_obj.payout - round_obj.stake),
            "status": round_obj.status,
            "settled_at": round_obj.settled_at.isoformat() if round_obj.settled_at else None,
        })

    return jsonify({
        "history": history,
        "count": len(history),
    }), 200
