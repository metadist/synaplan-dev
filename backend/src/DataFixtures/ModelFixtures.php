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
                'quality' => 6,
                'rating' => 0.5,
                'json' => [
                    'description' => 'Local model on synaplans company server in Germany. DeepSeek R1 is a Chinese Open Source LLM.',
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

