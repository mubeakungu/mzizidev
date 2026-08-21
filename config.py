import os
from datetime import timedelta

basedir = os.path.abspath(os.path.dirname(__file__))


class Config:
    SECRET_KEY = os.environ.get("SECRET_KEY", "dev-key-change-in-production")

    # PostgreSQL — swap in your Render/cPanel DATABASE_URL
    SQLALCHEMY_DATABASE_URI = os.environ.get(
        "DATABASE_URL", "postgresql://mzizibet:password@localhost:5432/mzizibet"
    )
    if SQLALCHEMY_DATABASE_URI.startswith("postgres://"):import os
from datetime import timedelta

basedir = os.path.abspath(os.path.dirname(__file__))


class Config:
    SECRET_KEY = os.environ.get("SECRET_KEY", "dev-key-change-in-production")

    # PostgreSQL — swap in your Render/cPanel DATABASE_URL
    SQLALCHEMY_DATABASE_URI = os.environ.get(
        "DATABASE_URL", "postgresql+psycopg://mzizibet:password@localhost:5432/mzizibet"
    )
    if SQLALCHEMY_DATABASE_URI.startswith("postgres://"):
        # Render gives postgres:// but SQLAlchemy 2.0+ wants postgresql+psycopg:// for psycopg3
        SQLALCHEMY_DATABASE_URI = SQLALCHEMY_DATABASE_URI.replace(
            "postgres://", "postgresql+psycopg://", 1
        )
    elif SQLALCHEMY_DATABASE_URI.startswith("postgresql://"):
        # Convert bare postgresql:// to postgresql+psycopg:// for psycopg3
        SQLALCHEMY_DATABASE_URI = SQLALCHEMY_DATABASE_URI.replace(
            "postgresql://", "postgresql+psycopg://", 1
        )
    SQLALCHEMY_TRACK_MODIFICATIONS = False
    # Session / auth
    PERMANENT_SESSION_LIFETIME = timedelta(hours=12)
    SESSION_COOKIE_SECURE = os.environ.get("FLASK_ENV") == "production"

    # M-Pesa Daraja — deposits/withdrawals
    MPESA_CONSUMER_KEY = os.environ.get("MPESA_CONSUMER_KEY", "")
    MPESA_CONSUMER_SECRET = os.environ.get("MPESA_CONSUMER_SECRET", "")
    MPESA_SHORTCODE = os.environ.get("MPESA_SHORTCODE", "")
    MPESA_PASSKEY = os.environ.get("MPESA_PASSKEY", "")
    MPESA_CALLBACK_URL = os.environ.get("MPESA_CALLBACK_URL", "")
    MPESA_ENV = os.environ.get("MPESA_ENV", "sandbox")  # sandbox | production

    # BCLB licensing
    BCLB_LICENSE_NUMBER = os.environ.get("BCLB_LICENSE_NUMBER", "")
    BCLB_LICENSE_HOLDER = os.environ.get("BCLB_LICENSE_HOLDER", "")
    BCLB_LICENSE_EXPIRY = os.environ.get("BCLB_LICENSE_EXPIRY", "")

    # Licensed game/odds providers. Note: an operator's BCLB license
    # covers the business, not the RNG itself — BCLB conditions
    # separately require the game/RNG software to hold its own
    # certification from an accredited testing lab (e.g. GLI, BMM,
    # iTech Labs) before it can run for real money. If your RNG is
    # already certified, set GAME_PROVIDER_* below to your own
    # certified engine's identifiers; otherwise plug in a certified
    # third-party aggregator's API keys here.
    GAME_PROVIDER_API_KEY = os.environ.get("GAME_PROVIDER_API_KEY", "")
    GAME_PROVIDER_BASE_URL = os.environ.get("GAME_PROVIDER_BASE_URL", "")
    ODDS_PROVIDER_API_KEY = os.environ.get("ODDS_PROVIDER_API_KEY", "")
    ODDS_PROVIDER_BASE_URL = os.environ.get("ODDS_PROVIDER_BASE_URL", "")

    # Responsible gambling defaults (BCLB compliance expects these to exist)
    DEFAULT_DAILY_DEPOSIT_LIMIT = 50000  # KES
    MIN_AGE = 18


class DevelopmentConfig(Config):
    DEBUG = True
    # Local dev: use a SQLite file so no Postgres server is required.
    # Set DATABASE_URL in your environment/.env if you want to test
    # against real Postgres locally instead.
    SQLALCHEMY_DATABASE_URI = os.environ.get(
        "DATABASE_URL", f"sqlite:///{os.path.join(basedir, 'dev.db')}"
    )


class ProductionConfig(Config):
    DEBUG = False


config = {
    "development": DevelopmentConfig,
    "production": ProductionConfig,
    "default": DevelopmentConfig,
}
        # Render gives postgres:// but SQLAlchemy 1.4+ wants postgresql://
        SQLALCHEMY_DATABASE_URI = SQLALCHEMY_DATABASE_URI.replace(
            "postgres://", "postgresql://", 1
        )
    SQLALCHEMY_TRACK_MODIFICATIONS = False
    # Session / auth
    PERMANENT_SESSION_LIFETIME = timedelta(hours=12)
    SESSION_COOKIE_SECURE = os.environ.get("FLASK_ENV") == "production"

    # M-Pesa Daraja — deposits/withdrawals
    MPESA_CONSUMER_KEY = os.environ.get("MPESA_CONSUMER_KEY", "")
    MPESA_CONSUMER_SECRET = os.environ.get("MPESA_CONSUMER_SECRET", "")
    MPESA_SHORTCODE = os.environ.get("MPESA_SHORTCODE", "")
    MPESA_PASSKEY = os.environ.get("MPESA_PASSKEY", "")
    MPESA_CALLBACK_URL = os.environ.get("MPESA_CALLBACK_URL", "")
    MPESA_ENV = os.environ.get("MPESA_ENV", "sandbox")  # sandbox | production

    # BCLB licensing
    BCLB_LICENSE_NUMBER = os.environ.get("BCLB_LICENSE_NUMBER", "")
    BCLB_LICENSE_HOLDER = os.environ.get("BCLB_LICENSE_HOLDER", "")
    BCLB_LICENSE_EXPIRY = os.environ.get("BCLB_LICENSE_EXPIRY", "")

    # Licensed game/odds providers. Note: an operator's BCLB license
    # covers the business, not the RNG itself — BCLB conditions
    # separately require the game/RNG software to hold its own
    # certification from an accredited testing lab (e.g. GLI, BMM,
    # iTech Labs) before it can run for real money. If your RNG is
    # already certified, set GAME_PROVIDER_* below to your own
    # certified engine's identifiers; otherwise plug in a certified
    # third-party aggregator's API keys here.
    GAME_PROVIDER_API_KEY = os.environ.get("GAME_PROVIDER_API_KEY", "")
    GAME_PROVIDER_BASE_URL = os.environ.get("GAME_PROVIDER_BASE_URL", "")
    ODDS_PROVIDER_API_KEY = os.environ.get("ODDS_PROVIDER_API_KEY", "")
    ODDS_PROVIDER_BASE_URL = os.environ.get("ODDS_PROVIDER_BASE_URL", "")

    # Responsible gambling defaults (BCLB compliance expects these to exist)
    DEFAULT_DAILY_DEPOSIT_LIMIT = 50000  # KES
    MIN_AGE = 18


class DevelopmentConfig(Config):
    DEBUG = True
    # Local dev: use a SQLite file so no Postgres server is required.
    # Set DATABASE_URL in your environment/.env if you want to test
    # against real Postgres locally instead.
    SQLALCHEMY_DATABASE_URI = os.environ.get(
        "DATABASE_URL", f"sqlite:///{os.path.join(basedir, 'dev.db')}"
    )


class ProductionConfig(Config):
    DEBUG = False


config = {
    "development": DevelopmentConfig,
    "production": ProductionConfig,
    "default": DevelopmentConfig,
}
