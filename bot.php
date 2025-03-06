<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * 1. Константы и пути
 */
define('BOT_TOKEN', '7789434677:AAGBzS8GY6B6vTDb-6W-1BbDeaXCKRpN1pw');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

// ID канала, куда отправляем файл с результатами
define('CHANNEL_CHAT_ID', '-1002414171386');

// Путь к файлу с вопросами
define('QA_FILE', 'qa.json');

// Папки для хранения сессий и итоговых ответов
define('SESSION_DIR', 'sessions');
define('ANSWER_DIR', 'answer');

// Создаём директории, если их нет
if (!is_dir(SESSION_DIR)) {
    mkdir(SESSION_DIR, 0777, true);
}
if (!is_dir(ANSWER_DIR)) {
    mkdir(ANSWER_DIR, 0777, true);
}

/**
 * 2. Функции отправки сообщений, документов и удаления сообщений
 */
function sendMessage($chat_id, $text, $reply_markup = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    file_get_contents(API_URL . "sendMessage?" . http_build_query($data));
}

function sendDocument($chat_id, $document_path, $caption = null) {
    $data = [
        'chat_id' => $chat_id,
        'caption' => $caption
    ];
    $file = new CURLFile(realpath($document_path));
    $data['document'] = $file;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, API_URL . "sendDocument");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $output = curl_exec($ch);
    curl_close($ch);
    return $output;
}

function deleteMessage($chat_id, $message_id) {
    file_get_contents(API_URL . "deleteMessage?" . http_build_query([
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]));
}

/**
 * 3. Работа с сессиями (файлы sessions/<chat_id>.json)
 */
function getSessionFile($chat_id) {
    return SESSION_DIR . '/' . $chat_id . '.json';
}

function loadSession($chat_id) {
    $file = getSessionFile($chat_id);
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            return $data;
        }
    }
    return null;
}

function saveSession($chat_id, $data) {
    $file = getSessionFile($chat_id);
    file_put_contents($file, json_encode($data));
}

/**
 * 4. Загрузка и разбор вопросов из qa.json
 *    - Если в тексте варианта есть "[правильный]", удаляем её для отображения и запоминаем индекс.
 */
function loadQuestions() {
    if (!file_exists(QA_FILE)) {
        return [];
    }
    $json = file_get_contents(QA_FILE);
    $rawQuestions = json_decode($json, true);
    if (!is_array($rawQuestions)) {
        return [];
    }
    $questions = [];
    foreach ($rawQuestions as $q) {
        $questionText = isset($q['question']) ? $q['question'] : '';
        $type = isset($q['type']) ? $q['type'] : 'inline';
        $rawOptions = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];
        
        $options = [];
        $correctIndexes = [];
        foreach ($rawOptions as $idx => $opt) {
            if (strpos($opt, '[правильный]') !== false) {
                $correctIndexes[] = $idx;
            }
            $cleanOption = str_replace('[правильный]', '', $opt);
            $options[] = trim($cleanOption);
        }
        
        $questions[] = [
            'question' => $questionText,
            'type' => $type,
            'options' => $options,
            'correct_indexes' => $correctIndexes
        ];
    }
    return $questions;
}

/**
 * 5. Отправка текущего вопроса
 *    - Отправляем вопрос с вариантами и inline-кнопки с цифрами.
 */
function sendCurrentQuestion(&$session) {
    // Используем вопросы из сессии (уже перемешанные)
    $questions = isset($session['questions']) ? $session['questions'] : loadQuestions();
    $index = $session['current_question'];
    
    if ($index >= count($questions)) {
        completeSurvey($session);
        return;
    }
    
    $q = $questions[$index];
    $chat_id = $session['chat_id'];
    
    // Формируем текст: вопрос + варианты ответа
    $text = "<b>Вопрос " . ($index + 1) . "/40:</b> " . $q['question'] . "\n\n";
    foreach ($q['options'] as $idx => $optionText) {
        $text .= ($idx + 1) . ") " . $optionText . "\n";
    }
    
    // Inline-кнопки с цифрами
    $keyboard = ['inline_keyboard' => []];
    foreach ($q['options'] as $idx => $optionText) {
        $buttonLabel = (string)($idx + 1);
        $keyboard['inline_keyboard'][] = [
            ['text' => $buttonLabel, 'callback_data' => $buttonLabel]
        ];
    }
    
    sendMessage($chat_id, $text, $keyboard);
}

/**
 * 6. Завершение опроса
 *    - Подсчитываем баллы (2.5 за каждый правильный ответ).
 *    - В итоговом файле вместо цифр выводим текст выбранного варианта с пометкой.
 */
function completeSurvey(&$session) {
    $chat_id = $session['chat_id'];
    $name   = isset($session['name']) ? $session['name'] : '';
    $branch = isset($session['branch']) ? $session['branch'] : '';
    $answers = isset($session['answers']) ? $session['answers'] : [];
    
    // Используем сохранённые вопросы из сессии
    $questions = isset($session['questions']) ? $session['questions'] : loadQuestions();
    
    $score = 0.0;
    $content = "Имя: $name\nФилиал: $branch\n\n";
    
    foreach ($questions as $i => $q) {
        $q_num = $i + 1;
        $content .= "Вопрос $q_num: " . $q['question'] . "\n";
        
        if (!isset($answers[$i])) {
            $content .= "Ответ: Нет ответа\n\n";
            continue;
        }
        
        $userChoice = $answers[$i]; // "1", "2", "3"...
        $userIndex = intval($userChoice) - 1;
        
        if (!isset($q['options'][$userIndex])) {
            $content .= "Ответ: Неизвестный вариант\n\n";
            continue;
        }
        
        $pickedOptionText = $q['options'][$userIndex];
        
        if (in_array($userIndex, $q['correct_indexes'], true)) {
            $score += 2.5;
            $mark = "[правильный]";
        } else {
            $mark = "[не правильный]";
        }
        
        $content .= "Ответ: " . $pickedOptionText . " " . $mark . "\n\n";
    }
    
    $scoreFmtRaw = number_format($score, 1, '.', '');
    $scoreFmt = rtrim(rtrim($scoreFmtRaw, '0'), '.');
    
    // Формируем имя файла: "<баллы>_<Филиал>_<Имя>.txt"
    $branchSafe = preg_replace('/[^а-яА-Яa-zA-Z0-9\s]/u', '_', $branch);
    $nameSafe   = preg_replace('/[^а-яА-Яa-zA-Z0-9\s]/u', '_', $name);
    $branchSafe = preg_replace('/\s+/', '_', $branchSafe);
    $nameSafe   = preg_replace('/\s+/', '_', $nameSafe);
    
    $file_name = $scoreFmt . "_" . $branchSafe . "_" . $nameSafe . ".txt";
    $file_path = ANSWER_DIR . '/' . $file_name;
    
    file_put_contents($file_path, $content);
    
    $session['completed'] = true;
    saveSession($chat_id, $session);
    
    sendMessage($chat_id, "Опрос завершён. Спасибо за участие! Вы набрали $scoreFmt баллов.");
    
    $caption = "Новые ответы от $name ($branch). Итог: $scoreFmt баллов";
    sendDocument(CHANNEL_CHAT_ID, $file_path, $caption);
}

/**
 * 7. Основная логика: получаем update и обрабатываем
 */
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) {
    exit;
}

// 7.1. Обработка inline-кнопок (callback_query)
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chat_id = $callback['message']['chat']['id'];
    $data = $callback['data']; // "1", "2", "3", ...
    
    $session = loadSession($chat_id);
    if (!$session) {
        sendMessage($chat_id, "Пожалуйста, начните опрос командой /start");
        exit;
    }
    
    // Если опрос уже завершён, игнорируем повторные нажатия
    if (!empty($session['completed'])) {
        exit;
    }
    
    // Удаляем предыдущее сообщение с вопросом, чтобы кнопки не оставались
    $message_id = $callback['message']['message_id'];
    deleteMessage($chat_id, $message_id);
    
    // Записываем ответ для текущего вопроса
    $currentIndex = $session['current_question'];
    $session['answers'][$currentIndex] = $data;
    $session['current_question']++;
    saveSession($chat_id, $session);
    
    // Переходим к следующему вопросу
    sendCurrentQuestion($session);
    exit;
}

// 7.2. Обработка обычных сообщений
if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = trim($message['text']);
    
    $session = loadSession($chat_id);
    
    // Команда /start
    if ($text === '/start') {
        if ($session && !empty($session['completed'])) {
            sendMessage($chat_id, "Вы уже прошли опрос. Новых опросов на данный момент нет.");
            exit;
        }
        $session = [
            'chat_id' => $chat_id,
            'state' => 'collect_name',
            'current_question' => 0,
            'answers' => []
        ];
        saveSession($chat_id, $session);
        sendMessage($chat_id, "Здравствуйте! Для начала, пожалуйста, введите ваше имя:");
        exit;
    }
    
    // Команда /reset – сброс
    if ($text === '/reset') {
        if ($session) {
            $sessionFile = getSessionFile($chat_id);
            if (file_exists($sessionFile)) {
                unlink($sessionFile);
            }
            if (!empty($session['completed']) && !empty($session['name']) && !empty($session['branch'])) {
                $branchSafe = preg_replace('/[^а-яА-Яa-zA-Z0-9\s]/u', '_', $session['branch']);
                $nameSafe   = preg_replace('/[^а-яА-Яa-zA-Z0-9\s]/u', '_', $session['name']);
                $branchSafe = preg_replace('/\s+/', '_', $branchSafe);
                $nameSafe   = preg_replace('/\s+/', '_', $nameSafe);
                foreach (glob(ANSWER_DIR . '/*.txt') as $f) {
                    if (strpos($f, $branchSafe) !== false && strpos($f, $nameSafe) !== false) {
                        unlink($f);
                    }
                }
            }
            sendMessage($chat_id, "Ваши данные сброшены. Введите /start для начала опроса заново.");
        } else {
            sendMessage($chat_id, "У вас нет активных данных. Введите /start для начала опроса.");
        }
        exit;
    }
    
    if (!$session) {
        sendMessage($chat_id, "Пожалуйста, начните опрос командой /start");
        exit;
    }
    
    // Сбор имени
    if ($session['state'] === 'collect_name') {
        $session['name'] = $text;
        $session['state'] = 'collect_branch';
        saveSession($chat_id, $session);
        sendMessage($chat_id, "Спасибо, {$text}. Теперь укажите ваш филиал:");
        exit;
    }
    
    // Сбор филиала и перемешивание вопросов
    if ($session['state'] === 'collect_branch') {
        $session['branch'] = $text;
        // Загружаем и перемешиваем вопросы
        $questions = loadQuestions();
        shuffle($questions);
        $session['questions'] = $questions;
        
        $session['state'] = 'in_progress';
        saveSession($chat_id, $session);
        sendCurrentQuestion($session);
        exit;
    }
    
    if ($session['state'] === 'in_progress') {
        sendMessage($chat_id, "Пожалуйста, выберите один из вариантов, нажав на соответствующую кнопку.");
        exit;
    }
    
    sendMessage($chat_id, "Неизвестная команда. Используйте /start для начала или /reset для сброса данных.");
}
?>
