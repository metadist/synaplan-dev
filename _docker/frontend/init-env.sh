#!/bin/sh
set -e

echo "🔧 Checking frontend environment..."

# Frontend .env.docker
if [ ! -f ".env.docker" ]; then
    echo "📝 Creating .env.docker..."
    cat > .env.docker << 'EOF'
# Synaplan Frontend - Docker Environment
# This file is auto-generated. Use .env.local for local overrides.

# API Backend URL
VITE_API_URL=http://localhost:8000

# Development settings
VITE_DEV_MODE=true
EOF
    echo "✅ .env.docker created"
else
    echo "✅ .env.docker already exists"
fi

# Check which env file to use
if [ -f ".env.local" ]; then
    echo "🎯 Using .env.local (local overrides)"
else
    echo "🎯 Using .env.docker (default)"
fi
