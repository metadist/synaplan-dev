#!/bin/bash
set -e

echo "🚀 Starting Synaplan Backend..."

# Wait for database to be ready (already handled by healthcheck, but double-check)
echo "⏳ Waiting for database connection..."
until php bin/console dbal:run-sql "SELECT 1" > /dev/null 2>&1; do
    echo "   Database is not ready yet - sleeping..."
    sleep 2
done
echo "✅ Database is ready!"

# Wait for Ollama to be ready and pull required models
if [ -n "$OLLAMA_BASE_URL" ]; then
    echo "🤖 Checking Ollama service..."
    OLLAMA_HOST=$(echo $OLLAMA_BASE_URL | sed 's|http://||' | sed 's|https://||')
    
    # Wait for Ollama to be ready
    until curl -f "$OLLAMA_BASE_URL/api/tags" > /dev/null 2>&1; do
        echo "   Ollama is not ready yet - sleeping..."
        sleep 3
    done
    echo "✅ Ollama is ready!"
    
    # Pull required models
    # Priority models (wait for completion)
    PRIORITY_MODELS=("mistral:7b" "bge-m3")
    # Large models (pull in background)
    BACKGROUND_MODELS=("deepseek-r1:14b")
    
    for MODEL in "${PRIORITY_MODELS[@]}"; do
        echo "📥 Checking if priority model '$MODEL' exists..."
        if ! curl -s "$OLLAMA_BASE_URL/api/tags" | grep -q "\"name\":\"$MODEL\""; then
            echo "   ⬇️  Pulling model '$MODEL' (waiting for completion)..."
            curl -X POST "$OLLAMA_BASE_URL/api/pull" \
                -H "Content-Type: application/json" \
                -d "{\"name\":\"$MODEL\"}"
            echo "   ✅ Model '$MODEL' pulled successfully"
        else
            echo "   ✅ Model '$MODEL' already exists"
        fi
    done
    
    for MODEL in "${BACKGROUND_MODELS[@]}"; do
        echo "📥 Checking if model '$MODEL' exists..."
        if ! curl -s "$OLLAMA_BASE_URL/api/tags" | grep -q "\"name\":\"$MODEL\""; then
            echo "   ⬇️  Pulling large model '$MODEL' in background..."
            (curl -X POST "$OLLAMA_BASE_URL/api/pull" \
                -H "Content-Type: application/json" \
                -d "{\"name\":\"$MODEL\"}" > /dev/null 2>&1 && \
                echo "   ✅ Background model '$MODEL' pulled successfully") &
            echo "   Model pull started in background"
        else
            echo "   ✅ Model '$MODEL' already exists"
        fi
    done
    
    echo "✅ Ollama models check completed!"
fi

# Run migrations
echo "🔄 Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
echo "✅ Migrations completed!"

# Load fixtures only in dev/test environment
if [ "$APP_ENV" = "dev" ] || [ "$APP_ENV" = "test" ]; then
    echo "🌱 Loading fixtures for $APP_ENV environment..."
    php bin/console doctrine:fixtures:load --no-interaction || echo "⚠️  Fixtures failed, continuing anyway..."
    echo "✅ Fixtures loaded!"
fi

# Clear cache
echo "🧹 Clearing cache..."
php bin/console cache:clear --no-warmup
php bin/console cache:warmup
echo "✅ Cache cleared!"

# Start FrankenPHP
echo "🎉 Starting FrankenPHP server..."
exec frankenphp php-server --listen 0.0.0.0:80 --root public/

