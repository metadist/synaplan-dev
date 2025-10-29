<?php

namespace App\DataFixtures;

use App\Entity\Model;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Loads AI Models from BMODELS table
 */
class ModelFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $models = [
            [
                'id' => 1,
                'service' => 'Ollama',
                'name' => 'deepseek-r1:14b',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'deepseek-r1:14b',
                'priceIn' => 0.092,
                'inUnit' => 'per1M',
                'priceOut' => 0.46,
                'outUnit' => 'per1M',
                'quality' => 7,
                'rating' => 8,
                'json' => [
                    'description' => 'Local model on synaplans company server in Germany. DeepSeek R1 is a Chinese Open Source LLM with reasoning capabilities.',
                    'features' => ['reasoning']
                ]
            ],
            [
                'id' => 6,
                'service' => 'Ollama',
                'name' => 'mistral',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'mistral:7b',
                'priceIn' => 0.095,
                'inUnit' => 'per1M',
                'priceOut' => 0.475,
                'outUnit' => '-',
                'quality' => 5,
                'rating' => 0,
                'json' => ['description' => 'Local model on synaplans company server in Germany. Mistral 8b model - internally used for RAG retrieval.']
            ],
            // ==================== GROQ MODELS ====================
            [
                'id' => 9,
                'service' => 'Groq',
                'name' => 'Llama 3.3 70b versatile',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'llama-3.3-70b-versatile',
                'priceIn' => 0.59,
                'inUnit' => 'per1M',
                'priceOut' => 0.79,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'Fast API service via groq',
                    'params' => [
                        'model' => 'llama-3.3-70b-versatile',
                        'reasoning_format' => 'hidden',
                        'messages' => []
                    ]
                ]
            ],
            [
                'id' => 17,
                'service' => 'Groq',
                'name' => 'llama-4-scout-17b-16e-instruct',
                'tag' => 'pic2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'meta-llama/llama-4-scout-17b-16e-instruct',
                'priceIn' => 0.11,
                'inUnit' => 'per1M',
                'priceOut' => 0.34,
                'outUnit' => 'per1M',
                'quality' => 8,
                'rating' => 0,
                'json' => [
                    'description' => 'Groq image processing and text extraction',
                    'prompt' => 'Describe image! List the texts in the image, if possible. If not, describe the image in short.',
                    'params' => [
                        'model' => 'llama-3.2-90b-vision-preview'
                    ]
                ]
            ],
            [
                'id' => 21,
                'service' => 'Groq',
                'name' => 'whisper-large-v3',
                'tag' => 'sound2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'whisper-large-v3',
                'priceIn' => 0.111,
                'inUnit' => 'perhour',
                'priceOut' => 0,
                'outUnit' => '-',
                'quality' => 7,
                'rating' => 1,
                'json' => [
                    'description' => 'Groq whisper model to extract text from a sound file.',
                    'params' => [
                        'file' => '*LOCALFILEPATH*',
                        'model' => 'whisper-large-v3',
                        'response_format' => 'text'
                    ]
                ]
            ],
            [
                'id' => 49,
                'service' => 'Groq',
                'name' => 'llama-4-maverick-17b-128e-instruct',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'meta-llama/llama-4-maverick-17b-128e-instruct',
                'priceIn' => 0.2,
                'inUnit' => 'per1M',
                'priceOut' => 0.6,
                'outUnit' => 'per1M',
                'quality' => 7,
                'rating' => 0,
                'json' => [
                    'description' => 'Groq Llama4 128e processing and text extraction',
                    'params' => [
                        'model' => 'meta-llama/llama-4-maverick-17b-128e-instruct'
                    ]
                ]
            ],
            [
                'id' => 53,
                'service' => 'Groq',
                'name' => 'deepseek-r1-distill-llama-70b',
                'tag' => 'chat',
                'selectable' => 0,
                'active' => 0,
                'providerId' => 'deepseek-r1-distill-llama-70b',
                'priceIn' => 0.75,
                'inUnit' => 'per1M',
                'priceOut' => 0.99,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 0,
                'json' => [
                    'description' => 'DeepSeek R1 Distilled model - NOT available on Groq API',
                    'params' => [
                        'model' => 'deepseek-r1-distill-llama-70b'
                    ],
                    'features' => ['reasoning']
                ]
            ],
            [
                'id' => 75,
                'service' => 'Groq',
                'name' => 'gpt-oss-20b',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'openai/gpt-oss-20b',
                'priceIn' => 0.10,
                'inUnit' => 'per1M',
                'priceOut' => 0.50,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAIs gpt-oss-20b Modell, bereitgestellt von Groq. Ein 21-Milliarden-Parameter-Modell mit Mixture-of-Experts-Architektur, optimiert für niedrige Latenz und Einsatz auf Consumer-Hardware.',
                    'params' => [
                        'model' => 'openai/gpt-oss-20b'
                    ],
                    'meta' => [
                        'context_window' => '131072',
                        'license' => 'Apache-2.0',
                        'quantization' => 'TruePoint Numerics'
                    ]
                ]
            ],
            [
                'id' => 76,
                'service' => 'Groq',
                'name' => 'gpt-oss-120b',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'openai/gpt-oss-120b',
                'priceIn' => 0.15,
                'inUnit' => 'per1M',
                'priceOut' => 0.75,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAIs gpt-oss-120b Modell, bereitgestellt von Groq. Ein 120-Milliarden-Parameter-Modell mit Mixture-of-Experts-Architektur, geeignet für anspruchsvolle agentische Anwendungen.',
                    'params' => [
                        'model' => 'openai/gpt-oss-120b'
                    ],
                    'meta' => [
                        'context_window' => '131072',
                        'license' => 'Apache-2.0',
                        'quantization' => 'TruePoint Numerics'
                    ]
                ]
            ],
            [
                'id' => 13,
                'service' => 'Ollama',
                'name' => 'bge-m3',
                'tag' => 'vectorize',
                'selectable' => 0,
                'active' => 1,
                'providerId' => 'bge-m3',
                'priceIn' => 0.19,
                'inUnit' => 'per1M',
                'priceOut' => 0,
                'outUnit' => '-',
                'quality' => 6,
                'rating' => 1,
                'json' => [
                    'description' => 'Vectorize text into synaplans MariaDB vector DB (local) for RAG',
                    'params' => [
                        'model' => 'bge-m3',
                        'input' => []
                    ]
                ]
            ],
            // ==================== OPENAI MODELS ====================
            [
                'id' => 25,
                'service' => 'OpenAI',
                'name' => 'dall-e-3',
                'tag' => 'text2pic',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'dall-e-3',
                'priceIn' => 0,
                'inUnit' => '-',
                'priceOut' => 0.12,
                'outUnit' => 'perpic',
                'quality' => 7,
                'rating' => 1,
                'json' => [
                    'description' => 'Open AIs famous text to image model on OpenAI cloud. Costs are 1:1 funneled.',
                    'params' => [
                        'model' => 'dall-e-3',
                        'size' => '1024x1024',
                        'quality' => 'standard',
                        'style' => 'vivid'
                    ]
                ]
            ],
            [
                'id' => 29,
                'service' => 'OpenAI',
                'name' => 'gpt-image-1',
                'tag' => 'text2pic',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gpt-image-1',
                'priceIn' => 5,
                'inUnit' => '-',
                'priceOut' => 0,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'Open AIs powerful image generation model on OpenAI cloud. Costs are 1:1 funneled.',
                    'params' => [
                        'model' => 'gpt-image-1'
                    ]
                ]
            ],
            [
                'id' => 30,
                'service' => 'OpenAI',
                'name' => 'gpt-4.1',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gpt-4.1',
                'priceIn' => 2,
                'inUnit' => 'per1M',
                'priceOut' => 8,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'Open AIs text model',
                    'params' => [
                        'model' => 'gpt-4.1'
                    ]
                ]
            ],
            [
                'id' => 41,
                'service' => 'OpenAI',
                'name' => 'tts-1 with Nova',
                'tag' => 'text2sound',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'tts-1',
                'priceIn' => 0.015,
                'inUnit' => 'per1000chars',
                'priceOut' => 0,
                'outUnit' => '-',
                'quality' => 8,
                'rating' => 1,
                'json' => [
                    'description' => 'Open AIs text to speech, defaulting on voice NOVA.',
                    'params' => [
                        'model' => 'tts-1',
                        'voice' => 'nova'
                    ]
                ]
            ],
            [
                'id' => 57,
                'service' => 'OpenAI',
                'name' => 'o1-preview',
                'tag' => 'chat',
                'selectable' => 0,
                'active' => 0,
                'providerId' => 'o1-preview',
                'priceIn' => 15,
                'inUnit' => 'per1M',
                'priceOut' => 60,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI o1-preview reasoning model (REQUIRES API TIER 5 - Not available for most accounts)',
                    'params' => [
                        'model' => 'o1-preview'
                    ],
                    'features' => ['reasoning'],
                    'supportsStreaming' => false
                ]
            ],
            [
                'id' => 89,
                'service' => 'OpenAI',
                'name' => 'o1-mini',
                'tag' => 'chat',
                'selectable' => 0,
                'active' => 0,
                'providerId' => 'o1-mini',
                'priceIn' => 3,
                'inUnit' => 'per1M',
                'priceOut' => 12,
                'outUnit' => 'per1M',
                'quality' => 8,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI o1-mini reasoning model (REQUIRES HIGHER API TIER - Not available for most accounts)',
                    'params' => [
                        'model' => 'o1-mini'
                    ],
                    'features' => ['reasoning'],
                    'supportsStreaming' => false
                ]
            ],
            [
                'id' => 59,
                'service' => 'OpenAI',
                'name' => 'o3',
                'tag' => 'chat',
                'selectable' => 0,
                'active' => 0,
                'providerId' => 'o3',
                'priceIn' => 2,
                'inUnit' => 'per1M',
                'priceOut' => 8,
                'outUnit' => 'per1M',
                'quality' => 8,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI o3 reasoning model (NOT YET AVAILABLE - Limited Preview Only)',
                    'params' => [
                        'model' => 'o3',
                        'reasoning_effort' => 'high'
                    ],
                    'features' => ['reasoning']
                ]
            ],
            [
                'id' => 70,
                'service' => 'OpenAI',
                'name' => 'gpt-5',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gpt-5',
                'priceIn' => 1.25,
                'inUnit' => 'per1M',
                'priceOut' => 10,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'Open AIs GPT 5 model - latest release',
                    'params' => [
                        'model' => 'gpt-5'
                    ]
                ]
            ],
            [
                'id' => 72,
                'service' => 'OpenAI',
                'name' => 'o3-pro',
                'tag' => 'chat',
                'selectable' => 0,
                'active' => 0,
                'providerId' => 'o3-pro',
                'priceIn' => 20,
                'inUnit' => 'per1M',
                'priceOut' => 80,
                'outUnit' => 'per1M',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI premium reasoning model (NOT AVAILABLE - API Error). More compute than o3 with higher reliability.',
                    'params' => [
                        'model' => 'o3-pro',
                        'reasoning_effort' => 'high'
                    ]
                ]
            ],
            [
                'id' => 73,
                'service' => 'OpenAI',
                'name' => 'gpt-4o-mini',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gpt-4o-mini',
                'priceIn' => 0.15,
                'inUnit' => 'per1M',
                'priceOut' => 0.6,
                'outUnit' => 'per1M',
                'quality' => 8,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI lightweight GPT-4o-mini model for fast and cost-efficient chat tasks. Optimized for lower latency and cheaper throughput.',
                    'params' => [
                        'model' => 'gpt-4o-mini'
                    ]
                ]
            ],
            // ==================== ADDITIONAL OPENAI MODELS ====================
            [
                'id' => 80,
                'service' => 'OpenAI',
                'name' => 'gpt-4o',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gpt-4o',
                'priceIn' => 2.5,
                'inUnit' => 'per1M',
                'priceOut' => 10,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI GPT-4o - Omni model with vision, audio and text capabilities.',
                    'params' => [
                        'model' => 'gpt-4o'
                    ],
                    'features' => ['vision', 'audio']
                ]
            ],
            [
                'id' => 81,
                'service' => 'OpenAI',
                'name' => 'gpt-4o (Vision)',
                'tag' => 'pic2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gpt-4o',
                'priceIn' => 2.5,
                'inUnit' => 'per1M',
                'priceOut' => 10,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI GPT-4o for image analysis and vision tasks.',
                    'prompt' => 'Describe the image in detail. Extract any text you see.',
                    'params' => [
                        'model' => 'gpt-4o'
                    ]
                ]
            ],
            [
                'id' => 82,
                'service' => 'OpenAI',
                'name' => 'whisper-1',
                'tag' => 'sound2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'whisper-1',
                'priceIn' => 0.006,
                'inUnit' => 'permin',
                'priceOut' => 0,
                'outUnit' => '-',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI Whisper model for audio transcription. Supports 50+ languages.',
                    'params' => [
                        'model' => 'whisper-1',
                        'response_format' => 'verbose_json'
                    ],
                    'features' => ['multilingual', 'translation']
                ]
            ],
            [
                'id' => 83,
                'service' => 'OpenAI',
                'name' => 'tts-1-hd (Alloy)',
                'tag' => 'text2sound',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'tts-1-hd',
                'priceIn' => 0.03,
                'inUnit' => 'per1000chars',
                'priceOut' => 0,
                'outUnit' => '-',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI high-quality text-to-speech with voice Alloy (neutral and balanced).',
                    'params' => [
                        'model' => 'tts-1-hd',
                        'voice' => 'alloy'
                    ]
                ]
            ],
            [
                'id' => 84,
                'service' => 'OpenAI',
                'name' => 'tts-1-hd (Echo)',
                'tag' => 'text2sound',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'tts-1-hd',
                'priceIn' => 0.03,
                'inUnit' => 'per1000chars',
                'priceOut' => 0,
                'outUnit' => '-',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI high-quality text-to-speech with voice Echo (male voice).',
                    'params' => [
                        'model' => 'tts-1-hd',
                        'voice' => 'echo'
                    ]
                ]
            ],
            [
                'id' => 85,
                'service' => 'OpenAI',
                'name' => 'tts-1-hd (Shimmer)',
                'tag' => 'text2sound',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'tts-1-hd',
                'priceIn' => 0.03,
                'inUnit' => 'per1000chars',
                'priceOut' => 0,
                'outUnit' => '-',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI high-quality text-to-speech with voice Shimmer (warm female voice).',
                    'params' => [
                        'model' => 'tts-1-hd',
                        'voice' => 'shimmer'
                    ]
                ]
            ],
            [
                'id' => 86,
                'service' => 'OpenAI',
                'name' => 'dall-e-2',
                'tag' => 'text2pic',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'dall-e-2',
                'priceIn' => 0,
                'inUnit' => '-',
                'priceOut' => 0.02,
                'outUnit' => 'perpic',
                'quality' => 6,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI DALL-E 2 for cost-effective image generation.',
                    'params' => [
                        'model' => 'dall-e-2',
                        'size' => '1024x1024'
                    ]
                ]
            ],
            [
                'id' => 87,
                'service' => 'OpenAI',
                'name' => 'text-embedding-3-small',
                'tag' => 'vectorize',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'text-embedding-3-small',
                'priceIn' => 0.02,
                'inUnit' => 'per1M',
                'priceOut' => 0,
                'outUnit' => '-',
                'quality' => 8,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI text embedding model (1536 dimensions) for RAG and semantic search.',
                    'params' => [
                        'model' => 'text-embedding-3-small'
                    ],
                    'meta' => [
                        'dimensions' => 1536
                    ]
                ]
            ],
            [
                'id' => 88,
                'service' => 'OpenAI',
                'name' => 'text-embedding-3-large',
                'tag' => 'vectorize',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'text-embedding-3-large',
                'priceIn' => 0.13,
                'inUnit' => 'per1M',
                'priceOut' => 0,
                'outUnit' => '-',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'OpenAI large text embedding model (3072 dimensions) for high-accuracy RAG.',
                    'params' => [
                        'model' => 'text-embedding-3-large'
                    ],
                    'meta' => [
                        'dimensions' => 3072
                    ]
                ]
            ],
            // ==================== GOOGLE MODELS ====================
            [
                'id' => 33,
                'service' => 'Google',
                'name' => 'ImaGen 3.0',
                'tag' => 'text2pic',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'imagen-3.0-generate-002',
                'priceIn' => 0,
                'inUnit' => '-',
                'priceOut' => 0.3,
                'outUnit' => 'perpic',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Imagen 3.0',
                    'params' => [
                        'model' => 'imagen-3.0-generate-002'
                    ]
                ]
            ],
            [
                'id' => 37,
                'service' => 'Google',
                'name' => 'Gemini 2.0 Flash',
                'tag' => 'text2sound',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-2.0-flash',
                'priceIn' => 0.1,
                'inUnit' => 'per1M',
                'priceOut' => 0.4,
                'outUnit' => 'per1M',
                'quality' => 8,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Speech Generation with Gemini 2.0 Flash',
                    'params' => [
                        'model' => 'gemini-2.0-flash'
                    ]
                ]
            ],
            [
                'id' => 45,
                'service' => 'Google',
                'name' => 'Veo 3.1',
                'tag' => 'text2vid',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'veo-3.1-generate-preview',
                'priceIn' => 0,
                'inUnit' => '-',
                'priceOut' => 0.35,
                'outUnit' => 'persec',
                'quality' => 10,
                'rating' => 1,
                'json' => [
                    'description' => 'Google Video Generation model Veo 3.1 - 8 second videos with audio',
                    'params' => [
                        'model' => 'veo-3.1-generate-preview'
                    ]
                ]
            ],
            [
                'id' => 61,
                'service' => 'Google',
                'name' => 'Gemini 2.5 Pro',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-2.5-pro-preview-06-05',
                'priceIn' => 2.5,
                'inUnit' => 'per1M',
                'priceOut' => 15,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'Googles Answer to the other LLM models',
                    'params' => [
                        'model' => 'gemini-2.5-pro-preview-06-05'
                    ]
                ]
            ],
            [
                'id' => 65,
                'service' => 'Google',
                'name' => 'Gemini 2.5 Pro',
                'tag' => 'pic2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-2.5-pro-preview-06-05',
                'priceIn' => 2.5,
                'inUnit' => 'per1M',
                'priceOut' => 15,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'Googles Powerhouse can also process images, not just text',
                    'prompt' => 'Describe the image in detail. Extract any text you see.',
                    'params' => [
                        'model' => 'gemini-2.5-pro-preview-06-05'
                    ]
                ]
            ],
            [
                'id' => 71,
                'service' => 'Google',
                'name' => 'Gemini 2.5 Flash Image',
                'tag' => 'text2pic',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gemini-2.5-flash-image-preview',
                'priceIn' => 0.3,
                'inUnit' => '-',
                'priceOut' => 0.039,
                'outUnit' => 'perpic',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'Google newest image generation and editing model (aka Nano-Banana). Low latency, multi-image fusion, character consistency and precise editing. All outputs are watermarked with SynthID.',
                    'params' => [
                        'model' => 'gemini-2.5-flash-image-preview'
                    ]
                ]
            ],
            // ==================== ANTHROPIC MODELS ====================
            [
                'id' => 69,
                'service' => 'Anthropic',
                'name' => 'Claude Opus 4',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'claude-opus-4-20250514',
                'priceIn' => 15,
                'inUnit' => 'per1M',
                'priceOut' => 75,
                'outUnit' => 'per1M',
                'quality' => 7,
                'rating' => 0.5,
                'json' => [
                    'description' => 'Claude Opus 4 of Anthropic as the alternative chat method.',
                    'params' => [
                        'model' => 'claude-opus-4-20250514'
                    ]
                ]
            ],
            [
                'id' => 74,
                'service' => 'Anthropic',
                'name' => 'Claude Sonnet 4',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'claude-sonnet-4-20250514',
                'priceIn' => 3,
                'inUnit' => 'per1M',
                'priceOut' => 15,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'Anthropic Claude Sonnet 4 model. Mid-tier reasoning and coding performance with large context window. Balanced between quality and cost.',
                    'params' => [
                        'model' => 'claude-sonnet-4-20250514'
                    ]
                ]
            ],
            // ==================== ADDITIONAL OLLAMA MODELS ====================
            [
                'id' => 2,
                'service' => 'Ollama',
                'name' => 'Llama 3.3 70b',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'llama3.3:70b',
                'priceIn' => 0.54,
                'inUnit' => 'per1M',
                'priceOut' => 0.73,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'Local model on synaplans company server in Germany. Metas Llama Model Version 3.3 with 70b parameters. Heavy load model and relatively slow, even on a dedicated NVIDIA card. Yet good quality!'
                ]
            ],
            [
                'id' => 3,
                'service' => 'Ollama',
                'name' => 'deepseek-r1:32b',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'deepseek-r1:32b',
                'priceIn' => 0.69,
                'inUnit' => 'per1M',
                'priceOut' => 0.91,
                'outUnit' => '-',
                'quality' => 8,
                'rating' => 8,
                'json' => [
                    'description' => 'Local model on synaplans company server in Germany. DeepSeek R1 is a Chinese Open Source LLM. This is the bigger version with 32b parameters. A bit slower, but more accurate!',
                    'features' => ['reasoning']
                ]
            ],
            [
                'id' => 78,
                'service' => 'Ollama',
                'name' => 'gpt-oss:20b',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gpt-oss:20b',
                'priceIn' => 0.12,
                'inUnit' => 'per1M',
                'priceOut' => 0.60,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'Local model on synaplans company server in Germany. OpenAIs open-weight GPT-OSS (20B). 128K context, Apache-2.0 license, MXFP4 quantization; supports tools/agentic use cases.',
                    'params' => [
                        'model' => 'gpt-oss:20b'
                    ],
                    'meta' => [
                        'context_window' => '128k',
                        'license' => 'Apache-2.0',
                        'quantization' => 'MXFP4'
                    ]
                ]
            ],
            [
                'id' => 79,
                'service' => 'Ollama',
                'name' => 'gpt-oss:120b',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'gpt-oss:120b',
                'priceIn' => 0.05,
                'inUnit' => 'per1M',
                'priceOut' => 0.25,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 1,
                'json' => [
                    'description' => 'Local model on synaplans company server in Germany. OpenAIs open-weight GPT-OSS (120B). 128K context, Apache-2.0 license, MXFP4 quantization; supports tools/agentic use cases.',
                    'params' => [
                        'model' => 'gpt-oss:120b'
                    ],
                    'meta' => [
                        'context_window' => '128k',
                        'license' => 'Apache-2.0',
                        'quantization' => 'MXFP4'
                    ]
                ]
            ],
        ];

        foreach ($models as $data) {
            // Insert with explicit ID using native SQL
            $connection = $manager->getConnection();
            
            // Convert JSON array to string
            $jsonData = json_encode($data['json']);
            
            $sql = "INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BACTIVE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BJSON) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        BSERVICE = VALUES(BSERVICE),
                        BNAME = VALUES(BNAME),
                        BTAG = VALUES(BTAG),
                        BSELECTABLE = VALUES(BSELECTABLE),
                        BACTIVE = VALUES(BACTIVE),
                        BPROVID = VALUES(BPROVID),
                        BPRICEIN = VALUES(BPRICEIN),
                        BINUNIT = VALUES(BINUNIT),
                        BPRICEOUT = VALUES(BPRICEOUT),
                        BOUTUNIT = VALUES(BOUTUNIT),
                        BQUALITY = VALUES(BQUALITY),
                        BRATING = VALUES(BRATING),
                        BJSON = VALUES(BJSON)";
            
            $connection->executeStatement($sql, [
                $data['id'],
                $data['service'],
                $data['name'],
                $data['tag'],
                $data['selectable'],
                $data['active'],
                $data['providerId'],
                $data['priceIn'],
                $data['inUnit'],
                $data['priceOut'],
                $data['outUnit'],
                $data['quality'],
                $data['rating'],
                $jsonData
            ]);
        }

        $manager->flush();
    }
}

