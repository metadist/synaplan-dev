# Synaplan - AI-Powered Knowledge Management System

AI-powered knowledge management with chat, document processing, and RAG (Retrieval-Augmented Generation).

## 🚀 Quick Start

### Prerequisites

- Docker & Docker Compose
- Git

### Installation

```bash
git clone <repository-url>
cd synaplan-dev
AUTO_DOWNLOAD_MODELS=true docker compose up -d
```

That's it! Docker Compose automatically:
- ✅ Starts Backend (Symfony + PHP 8.3) on port 8000
- ✅ Starts Frontend (Vue.js + Vite) on port 5173
- ✅ Creates environment files (`backend/.env.docker`, `frontend/.env.docker`)
- ✅ Runs database migrations
- ✅ Seeds test users and fixtures
- ✅ Downloads AI models in background
- ✅ System ready in ~20 seconds (models continue downloading)

**Check model download progress:**
```bash
docker compose logs -f backend | grep -i "model\|background"
```

## 🌐 Access

| Service | URL | Description |
|---------|-----|-------------|
| Frontend | http://localhost:5173 | Vue.js Web App |
| Backend API | http://localhost:8000 | Symfony REST API |
| phpMyAdmin | http://localhost:8082 | Database Management |
| MailHog | http://localhost:8025 | Email Testing |
| Ollama | http://localhost:11435 | AI Models API |

## 👤 Test Users

| Email | Password | Level |
|-------|----------|-------|
| admin@synaplan.com | admin123 | BUSINESS |
| demo@synaplan.com | demo123 | PRO |
| test@example.com | test123 | NEW |

## 📁 Project Structure

```
synaplan-dev/
├── _devextras/          # Development extras
├── _docker/             # Docker configurations
│   ├── backend/         # Backend Dockerfile & scripts
│   └── frontend/        # Frontend Dockerfile & nginx
├── backend/             # Symfony Backend (PHP 8.3)
├── frontend/            # Vue.js Frontend
└── docker-compose.yml   # Main orchestration
```

## ⚙️ Environment Configuration

Environment files are auto-generated on first start:
- `backend/.env.docker` (auto-created by backend container)
- `frontend/.env.docker` (auto-created by frontend container)

For local customization, create:
- `backend/.env.local` (overrides `.env.docker`)
- `frontend/.env.local` (overrides `.env.docker`)

Example files provided:
- `backend/.env.docker.example` (reference)
- `frontend/.env.docker.example` (reference)

## 🛠️ Development

```bash
# View logs
docker compose logs -f

# Restart services
docker compose restart backend
docker compose restart frontend

# Reset database (deletes all data!)
docker compose down -v
docker compose up -d

# Run migrations
docker compose exec backend php bin/console doctrine:migrations:migrate

# Install packages
docker compose exec backend composer require <package>
docker compose exec frontend npm install <package>
```

## 🤖 AI Models

Models are downloaded **on-demand** when first used:
- **mistral:7b** - Main chat model (4.1 GB) - Downloaded on first chat
- **bge-m3** - Embedding model for RAG (2.2 GB) - Downloaded when using document search

### Pre-download Models (Recommended)

To download models during startup (in background):
```bash
AUTO_DOWNLOAD_MODELS=true docker compose up -d
```

**The backend starts immediately** while models download in parallel. Monitor progress:
```bash
docker compose logs -f backend
```

You'll see messages like:
- `[Background] ⏳ Model 'mistral:7b' download in progress...`
- `[Background] ✅ Model 'mistral:7b' downloaded successfully!`

## ✨ Features

- ✅ AI Chat with multiple providers (Ollama, OpenAI, Anthropic, Groq, Gemini)
- ✅ RAG System with document-based answers
- ✅ Document processing (PDF, Word, Excel, Images)
- ✅ Audio transcription (Whisper.cpp)
- ✅ Vector search (MariaDB Vector DB)
- ✅ Session management
- ✅ Multi-user with roles
- ✅ Responsive UI (Vue.js + TailwindCSS)

## 📄 License

See [LICENSE.md](LICENSE.md)
