#!/bin/bash
set -e

echo "🚀 Starting Synaplan Backend..."

# Initialize environment configuration
/usr/local/bin/init-env.sh

# Wait for database to be ready (already handled by healthcheck, but double-check)
echo "⏳ Waiting for database connection..."
until php bin/console dbal:run-sql "SELECT 1" > /dev/null 2>&1; do
    echo "   Database is not ready yet - sleeping..."
    sleep 2
done
echo "✅ Database is ready!"

# Run migrations FIRST
echo "🔄 Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || {
    echo "⚠️  Migrations failed, trying schema update..."
    php bin/console doctrine:schema:update --force
}
echo "✅ Database schema ready!"

# Load fixtures BEFORE model downloads (on first run only)
FIXTURES_MARKER="/var/www/html/var/.fixtures_loaded"

if [ "$APP_ENV" = "dev" ] || [ "$APP_ENV" = "test" ]; then
    if [ ! -f "$FIXTURES_MARKER" ]; then
        echo "🌱 First run detected - loading test data (users, models, configs)..."
        
        # Ensure schema is up to date
        echo "   Updating database schema (complete)..."
        php bin/console doctrine:schema:update --force --complete || true
        
        # Load fixtures
        echo "   Loading fixtures..."
        if php bin/console doctrine:fixtures:load --no-interaction 2>&1 | tee /tmp/fixtures.log; then
            # Only create marker if fixtures loaded successfully
            if grep -q "loading App" /tmp/fixtures.log; then
                touch "$FIXTURES_MARKER"
                echo ""
                echo "✅ Fixtures loaded successfully!"
                echo "   👤 Admin user: admin@synaplan.com / admin123"
                echo "   👤 Demo user: demo@synaplan.com / demo123"
                echo "   👤 Test user: test@example.com / test123"
            else
                echo "⚠️  Fixtures might have failed - no marker created"
            fi
        else
            echo "❌ Fixtures loading failed!"
            echo "   Please run manually: docker compose exec backend php bin/console doctrine:fixtures:load"
        fi
    else
        echo "✅ Fixtures already loaded"
        echo "   👤 Login: admin@synaplan.com / admin123"
        echo "   💡 To reload: rm backend/var/.fixtures_loaded && docker compose restart backend"
    fi
fi

# Ollama model downloads LAST (optional - models are downloaded on-demand)
if [ -n "$OLLAMA_BASE_URL" ] && [ "$AUTO_DOWNLOAD_MODELS" = "true" ]; then
    echo ""
    echo "🤖 AUTO_DOWNLOAD_MODELS=true - Starting AI model downloads in background..."
    echo "   Backend will start immediately, models download in parallel"
    echo "   Use 'docker compose logs -f backend' to monitor progress"
    
    # Background script to pull models
    (
        # Wait for Ollama to be ready
        echo "[Background] Waiting for Ollama service..."
        until curl -f "$OLLAMA_BASE_URL/api/tags" > /dev/null 2>&1; do
            sleep 3
        done
        echo "[Background] ✅ Ollama ready, starting downloads..."
        
        # Pull required models
        MODELS=("mistral:7b" "bge-m3")
        
        for MODEL in "${MODELS[@]}"; do
            if ! curl -s "$OLLAMA_BASE_URL/api/tags" | grep -q "\"name\":\"$MODEL\""; then
                echo "[Background] 📥 Downloading model: $MODEL (this may take several minutes)..."
                echo "[Background] ⏳ Model '$MODEL' download in progress..."
                
                if curl -X POST "$OLLAMA_BASE_URL/api/pull" \
                    -H "Content-Type: application/json" \
                    -d "{\"name\":\"$MODEL\"}" > /dev/null 2>&1; then
                    echo "[Background] ✅ Model '$MODEL' downloaded successfully!"
                else
                    echo "[Background] ⚠️  Model '$MODEL' download failed (will retry on first use)"
                fi
            else
                echo "[Background] ✅ Model '$MODEL' already available"
            fi
        done
        
        echo "[Background] 🎉 All model downloads completed!"
    ) &
    
    echo "✅ Model download started (PID: $!)"
else
    echo ""
    echo "⏭️  Skipping automatic model download"
    echo "   💡 Tip: Use 'AUTO_DOWNLOAD_MODELS=true docker compose up -d' to pre-download models"
    echo "   Models will be downloaded automatically when first used"
fi

# Clear cache
echo "🧹 Clearing cache..."
php bin/console cache:clear --no-warmup
php bin/console cache:warmup
echo "✅ Cache cleared!"

# Start FrankenPHP
echo "🎉 Starting FrankenPHP server..."
exec frankenphp php-server --listen 0.0.0.0:80 --root public/

