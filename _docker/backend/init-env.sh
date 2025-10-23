#!/bin/bash
set -e

echo "🔧 Checking environment files..."

# Function to create .env from .env.example if it doesn't exist
create_env_from_example() {
    local ENV_FILE=$1
    local EXAMPLE_FILE=$2
    
    if [ ! -f "$ENV_FILE" ]; then
        if [ -f "$EXAMPLE_FILE" ]; then
            echo "📝 Creating $ENV_FILE from $EXAMPLE_FILE..."
            cp "$EXAMPLE_FILE" "$ENV_FILE"
            echo "✅ $ENV_FILE created from example"
        else
            echo "⚠️  Warning: $EXAMPLE_FILE not found, skipping"
            return 1
        fi
    else
        echo "✅ $ENV_FILE already exists"
    fi
    return 0
}

# Root .env for Docker Compose (bind-mounted, accessible from container)
ROOT_DIR="/var/www/html/.."
ROOT_ENV="$ROOT_DIR/.env"
ROOT_EXAMPLE="$ROOT_DIR/.env.example"

if [ -f "$ROOT_EXAMPLE" ]; then
    create_env_from_example "$ROOT_ENV" "$ROOT_EXAMPLE" 2>/dev/null || true
fi

# Backend .env
BACKEND_DIR="/var/www/html"
BACKEND_ENV="$BACKEND_DIR/.env"
BACKEND_EXAMPLE="$BACKEND_DIR/.env.example"

# Try to create .env from .env.example if it doesn't exist
if ! create_env_from_example "$BACKEND_ENV" "$BACKEND_EXAMPLE"; then
    echo "📝 Creating default .env for backend..."
    cat > "$BACKEND_ENV" << 'EOF'
###> symfony/framework-bundle ###
APP_ENV=dev
APP_SECRET=change_me_in_production_12345678901234567890
###< symfony/framework-bundle ###

###> doctrine/doctrine-bundle ###
DATABASE_WRITE_URL=mysql://synaplan_user:synaplan_password@db:3306/synaplan?serverVersion=11.8&charset=utf8mb4
DATABASE_READ_URL=mysql://synaplan_user:synaplan_password@db:3306/synaplan?serverVersion=11.8&charset=utf8mb4
###< doctrine/doctrine-bundle ###

###> symfony/messenger ###
MESSENGER_TRANSPORT_DSN=redis://redis:6379/messages
###< symfony/messenger ###

###> redis ###
REDIS_URL=redis://redis:6379
###< redis ###

###> AI Providers ###
OLLAMA_BASE_URL=http://ollama:11434
TIKA_BASE_URL=http://tika:9998
AI_DEFAULT_PROVIDER=ollama
###< AI Providers ###

###> External AI API Keys (optional) ###
ANTHROPIC_API_KEY=
OPENAI_API_KEY=
GROQ_API_KEY=
GOOGLE_GEMINI_API_KEY=
###< External AI API Keys ###

###> lexik/jwt-authentication-bundle ###
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=change_me_in_production
###< lexik/jwt-authentication-bundle ###

###> CORS ###
CORS_ALLOW_ORIGIN=^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$
###< CORS ###

###> Mailer ###
MAILER_DSN=null://null
###< Mailer ###
EOF
    echo "✅ Default backend/.env created"
fi

echo "✅ Environment check completed!"
