<?php
// AI の口（OpenAI 互換の /v1/chat/completions）。この名前（klawnavi_config.php）に変えて使う。
// 書かなければ、選択肢を押して答える形で AI なしに動く。
define('KLN_API_BASE', 'http://127.0.0.1:11434/v1');   // 例: 社内の Ollama
define('KLN_API_KEY', 'ここにキー');
// define('KLN_MODEL', 'gemma4');                     // モデル名（書かなければ gemma4）
