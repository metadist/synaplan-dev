#!/bin/bash
set -e

echo "🔧 Checking environment files..."

# Backend .env.local (Symfony prefers .env.local over .env in dev/prod)
BACKEND_ENV_LOCAL="/var/www/html/.env.local"

if [ ! -f "$BACKEND_ENV_LOCAL" ]; then
    echo "📝 Creating backend/.env.local with Docker configuration..."
    cat > "$BACKEND_ENV_LOCAL" << 'EOF'
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

###> Tika Configuration ###
TIKA_TIMEOUT_MS=30000
TIKA_RETRIES=3
TIKA_RETRY_BACKOFF_MS=1000
TIKA_HTTP_USER=
TIKA_HTTP_PASS=
TIKA_MIN_LENGTH=10
TIKA_MIN_ENTROPY=2.0
###< Tika Configuration ###

###> PDF Rasterizer Configuration ###
RASTERIZE_DPI=150
RASTERIZE_PAGE_CAP=10
RASTERIZE_TIMEOUT_MS=30000
###< PDF Rasterizer Configuration ###

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
EOF
    echo "✅ backend/.env.local created (overrides .env)"
else
    echo "✅ backend/.env.local already exists (not overwriting)"
fi

echo "✅ Environment check completed!"

