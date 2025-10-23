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

# Option 1: Quick start (models download on-demand, ready in ~20 seconds)
docker compose up -d

# Option 2: Pre-download AI models (ready immediately, takes ~5-10 minutes)
AUTO_DOWNLOAD_MODELS=true docker compose up -d
```

Docker Compose automatically:
- ✅ Creates `.env` from `.env.example` (Docker config)
- ✅ Creates `backend/.env` and `frontend/.env` (app configs)
- ✅ Starts Backend (Symfony + PHP 8.3) on port 8000
- ✅ Starts Frontend (Vue.js + Vite) on port 5173
- ✅ Runs database migrations
- ✅ Seeds test users and fixtures
- ✅ System ready in ~20 seconds!

**AI Model Download Behavior:**
- **Default** (`docker compose up -d`): Models are **NOT** downloaded automatically
  - ⚡ Fast startup (~20 seconds)
  - 📥 Models download automatically when first used
  - 💡 Best for development
  
- **With AUTO_DOWNLOAD_MODELS=true**: Pre-downloads models in background
  - 🔄 Backend starts immediately (~20 seconds)
  - 📦 Models download in parallel (mistral:7b, bge-m3)
  - ⏱️ Total download time: ~5-10 minutes
  - ✅ Models ready immediately after download
  
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

## 🧠 RAG System

The system includes a full RAG (Retrieval-Augmented Generation) pipeline:

- **Upload**: Multi-level processing (Extract Only, Extract + Vectorize, Full Analysis)
- **Extraction**: Tika (documents), Tesseract OCR (images), Whisper (audio)
- **Vectorization**: bge-m3 embeddings (1024 dimensions) via Ollama
- **Storage**: Native MariaDB VECTOR type with VEC_DISTANCE_COSINE similarity search
- **Search**: Semantic search UI with configurable thresholds and group filtering
- **Sharing**: Private by default, public sharing with optional expiry

## 🎙️ Audio Transcription

Audio files are automatically transcribed using **Whisper.cpp** when uploaded:

- **Supported formats**: mp3, wav, ogg, m4a, opus, flac, webm, aac, wma
- **Automatic conversion**: FFmpeg converts all audio to optimal format (16kHz mono WAV)
- **Models**: tiny, base (default), small, medium, large - configurable via `.env`
- **Setup**: 
  - **Docker**: Pre-installed, download models on first run
  - **Local**: Install [whisper.cpp](https://github.com/ggerganov/whisper.cpp) and FFmpeg, configure paths in `.env`

**Environment variables** (see `.env.example`):
```bash
WHISPER_BINARY=/usr/local/bin/whisper    # Whisper.cpp binary path
WHISPER_MODELS_PATH=/var/www/html/var/whisper  # Model storage
WHISPER_DEFAULT_MODEL=base               # tiny|base|small|medium|large
WHISPER_ENABLED=true                     # Enable/disable transcription
FFMPEG_BINARY=/usr/bin/ffmpeg           # FFmpeg for audio conversion
```

If Whisper is unavailable, audio processing is skipped gracefully (no errors).

## 📱 WhatsApp Business API Integration

SynaPlan integrates with **Meta's official WhatsApp Business API** for bidirectional messaging.

### Setup:
1. **Create WhatsApp Business Account**: [Meta Business Suite](https://business.facebook.com/)
2. **Get Credentials**: Access Token, Phone Number ID, Business Account ID
3. **Set Environment Variables**:
```bash
WHATSAPP_ACCESS_TOKEN=your_access_token
WHATSAPP_PHONE_NUMBER_ID=your_phone_number_id
WHATSAPP_BUSINESS_ACCOUNT_ID=your_business_account_id
WHATSAPP_WEBHOOK_VERIFY_TOKEN=your_verify_token
WHATSAPP_ENABLED=true
```
4. **Configure Webhook in Meta**:
   - Callback URL: `https://your-domain.com/api/v1/webhooks/whatsapp`
   - Verify Token: Same as `WHATSAPP_WEBHOOK_VERIFY_TOKEN`
   - Subscribe to: `messages`

### Phone Verification (Required):
Users must verify their phone number via WhatsApp to unlock full features:
- **ANONYMOUS** (not verified): 10 messages, 2 images (very limited)
- **NEW** (verified): 50 messages, 5 images, 2 videos
- **PRO/TEAM/BUSINESS**: Full subscription limits

Verification Flow:
1. User enters phone number in web interface
2. 6-digit code sent via WhatsApp
3. User confirms code
4. Phone linked to account → full access
5. User can remove link anytime

### Supported Features:
- ✅ Text Messages (send & receive)
- ✅ Media Messages (images, audio, video, documents)
- ✅ Audio Transcription (via Whisper.cpp)
- ✅ Phone Verification System
- ✅ Full AI Pipeline (PreProcessor → Classifier → Handler)
- ✅ Rate Limiting per subscription level
- ✅ Message status tracking

### Message Flow:
```
WhatsApp User → Meta Webhook → /api/v1/webhooks/whatsapp
  → Message Entity → PreProcessor (files, audio transcription)
  → Classifier (sorting, tool detection) → InferenceRouter
  → AI Handler (Chat/RAG/Tools) → Response → WhatsApp
```

## 📧 Email Channel Integration

SynaPlan supports email-based AI conversations with smart chat context management.

### Email Addresses:
- **General**: `smart@synaplan.com` - Creates general chat conversation
- **Keyword-based**: `smart+keyword@synaplan.com` - Creates dedicated chat context
  - Example: `smart+project@synaplan.com` for project discussions
  - Example: `smart+support@synaplan.com` for support tickets

### Features:
- ✅ **Automatic User Detection**: Registered users get their own rate limits
- ✅ **Anonymous Email Support**: Unknown senders get ANONYMOUS limits
- ✅ **Chat Context**: Email threads become chat conversations
- ✅ **Spam Protection**: 
  - Max 10 emails/hour per unknown address
  - Automatic blacklisting for spammers
- ✅ **Email Threading**: Replies stay in the same chat context
- ✅ **Unified Rate Limits**: Same limits across Email, WhatsApp, Web

### How It Works:
```
User sends email to smart@synaplan.com
  → System checks if email is registered user
  → If yes: Use user's rate limits
  → If no: Create anonymous user with ANONYMOUS limits
  → Parse keyword from recipient (smart+keyword@)
  → Find or create chat context
  → Process through AI pipeline
  → Send response via email (TODO: requires SMTP)
```

### Rate Limits (Unified):
- **Registered User Email** = User's subscription limits
- **Unknown Email** = ANONYMOUS limits (10 messages total)
- **Spam Detection**: Auto-blacklist after 10 emails/hour

## 🔌 External Channel Integration (Generic)

The API also supports other external channels via webhooks authenticated with API keys:

### Setup:
1. **Create API Key**: `POST /api/v1/apikeys` (requires JWT login)
   ```json
   { "name": "Email Integration", "scopes": ["webhooks:*"] }
   ```
   Returns: `sk_abc123...` (store securely - shown only once!)

2. **Use Webhooks**: Send messages via API key authentication
   - Header: `X-API-Key: sk_abc123...` or
   - Query: `?api_key=sk_abc123...`

### Endpoints:
- **Email**: `POST /api/v1/webhooks/email`
- **WhatsApp**: `POST /api/v1/webhooks/whatsapp`
- **Generic**: `POST /api/v1/webhooks/generic`

Example (Email):
```bash
curl -X POST https://your-domain.com/api/v1/webhooks/email \
  -H "X-API-Key: sk_your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "from": "user@example.com",
    "subject": "Question",
    "body": "Hello, how can I help?"
  }'
```

**Response**: AI-generated reply based on message content

### API Key Management:
- `GET /api/v1/apikeys` - List keys
- `POST /api/v1/apikeys` - Create key
- `PATCH /api/v1/apikeys/{id}` - Update (activate/deactivate)
- `DELETE /api/v1/apikeys/{id}` - Revoke key

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
- `backend/.env.local` (auto-created by backend container, only if not exists)
- `frontend/.env.docker` (auto-created by frontend container)

**Note:** `.env.local` is never overwritten. To reset: delete the file and restart container.

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

- ✅ **AI Chat**: Multiple providers (Ollama, OpenAI, Anthropic, Groq, Gemini)
- ✅ **RAG System**: Semantic search with MariaDB VECTOR + bge-m3 embeddings (1024 dim)
- ✅ **Document Processing**: PDF, Word, Excel, Images (Tika + OCR)
- ✅ **Audio Transcription**: Whisper.cpp integration
- ✅ **File Management**: Upload, share (public/private), organize with expiry
- ✅ **App Modes**: Easy mode (simplified) and Advanced mode (full features)
- ✅ **Security**: Private files by default, secure sharing with tokens
- ✅ **Multi-user**: Role-based access with JWT authentication
- ✅ **Responsive UI**: Vue.js 3 + TypeScript + Tailwind CSS

## 📄 License

See [LICENSE](LICENSE)
