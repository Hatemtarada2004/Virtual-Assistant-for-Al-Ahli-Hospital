<?php

/**
 * Example local environment settings.
 *
 * Copy this file to app/config/env.php on a new machine, then fill in your
 * local database settings and OpenRouter API key.
 */

return [
    // Database
    'db_host'     => 'localhost',
    'db_port'     => '3306',
    'db_name'     => 'ahli_hospital',
    'db_user'     => 'root',
    'db_password' => '',
    'db_charset'  => 'utf8mb4',

    // Chat AI
    'chat_ai_provider' => 'openai',
    'chat_runtime'     => 'llm_orchestrator',
    'llm_receptionist_enabled' => true,
    'llm_receptionist_temperature' => 0.65,
    'openai_api_url'   => 'https://openrouter.ai/api/v1/chat/completions',
    'openai_api_key'   => 'PUT_YOUR_OPENROUTER_KEY_HERE',
    'openai_model'     => 'openai/gpt-4.1-mini',
    'openai_timeout'   => 30,
    'openai_extra_headers' => [
        'HTTP-Referer' => 'http://localhost/Hospital',
        'X-Title'      => 'Ahli Hospital Chatbot',
    ],

    // RAG retrieval sidecar
    'rag_enabled' => true,
    'rag_url'     => 'http://127.0.0.1:8011',
    'rag_top_k'   => 5,
    'rag_timeout' => 1.0,

    // App
    'app_env'   => 'development',
    'app_debug' => true,
    'app_url'   => 'http://localhost/Hospital/public',

    // Mail / email verification
    'mail_from' => 'no-reply@ahli-hospital.local',

    // Logging
    'log_path' => __DIR__ . '/../../storage/logs/app.log',
];
