<?php
/**
 * ЭкТен — копирование новых постов из группы ВКонтакте в канал Telegram и канал MAX.
 *
 * Что делает: при каждом запуске (по расписанию, раз в 5 минут) читает стену группы ВК
 * и публикует НОВЫЕ посты: сначала картинки (одна — фото, несколько — альбом), затем текст.
 * Чего не делает: не отвечает на сообщения, не читает сообщения людей, не хранит данные
 * подписчиков. В журнал пишет только время, номер поста ВК, площадку и результат.
 *
 * Команды (запуск: php8.3 crosspost.php <команда>):
 *   --cron                обычный запуск по расписанию
 *   --check               проверить ключи и каналы, ничего не публикуя
 *   --dry-run [--post=N]  показать, что было бы отправлено, ничего не публикуя
 *   --test-post=N         опубликовать пост ВК №N в ТЕСТОВЫЕ каналы (для проверки)
 *   --status              краткая сводка
 *   --retry=N             повторить неудавшуюся публикацию поста N
 *   --selftest            самопроверка без интернета
 *
 * Остановить: создать рядом файл STOP (программа будет сразу выходить) или выключить задание CRON.
 */
declare(strict_types=1);

const APP_VERSION = '1.0.0';
const VK_API = 'https://api.vk.com/method/';
const VK_V = '5.199';
const TG_API = 'https://api.telegram.org/bot';
const MAX_API = 'https://platform-api2.max.ru';

// Лимиты из официальной документации (core.telegram.org/bots/api, dev.max.ru/docs-api).
const TG_TEXT_LIMIT = 4096;               // sendMessage: 1-4096 знаков
const TG_ALBUM_MAX = 10;                  // sendMediaGroup: 2-10 элементов
const TG_PHOTO_MAX_BYTES = 10485760;      // sendPhoto: до 10 МБ при загрузке файлом
const TG_PHOTO_MAX_WH = 10000;            // ширина + высота не больше 10000
const MAX_TEXT_LIMIT = 4000;              // POST /messages: text до 4000 знаков
const MAX_ATTACH_MAX = 12;                // до 12 изображений в одном сообщении
const MAX_PHOTO_MAX_BYTES = 52428800;     // до 50 МБ
const MAX_PHOTO_MAX_SIDE = 7680;          // не более 7680 x 7680 px

const WALL_COUNT = 20;                    // сколько последних постов смотреть за раз
const GIVE_UP_AFTER = 172800;             // 48 часов: дальше повторять только по --retry
const RUN_TIME_BUDGET = 240;              // секунд на один запуск (CRON каждые 5 минут)
const BACKOFF_MIN = [5, 10, 20, 40, 60, 120, 240, 360];

date_default_timezone_set('Europe/Moscow');

final class ApiError extends RuntimeException
{
    public string $apiCode;

    public function __construct(string $message, string $apiCode = '')
    {
        parent::__construct($message);
        $this->apiCode = $apiCode;
    }
}

/** Общее состояние запуска: настройки, пути, секреты для маскировки. */
final class Ctx
{
    public static array $cfg = [];
    public static string $base = __DIR__;
    public static string $data = __DIR__ . '/data';
    public static array $secrets = [];
    /** @var callable|null */
    public static $fakeHttp = null;
    /** @var callable|null */
    public static $clock = null;
    public static bool $quiet = false;
    public static array $tmpFiles = [];
}

function now(): int
{
    return Ctx::$clock ? (int)(Ctx::$clock)() : time();
}

function pause(float $seconds): void
{
    if (Ctx::$fakeHttp === null && $seconds > 0) {
        usleep((int)($seconds * 1000000));
    }
}

function say(string $line): void
{
    if (!Ctx::$quiet) {
        echo $line, PHP_EOL;
    }
}

/** Убирает из строки ключи и токены, обрезает до разумной длины. */
function redact(string $s): string
{
    foreach (Ctx::$secrets as $secret) {
        if ($secret !== '') {
            $s = str_replace($secret, '***', $s);
        }
    }
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return mb_substr(trim($s), 0, 200);
}

function mask(string $value): string
{
    if ($value === '') {
        return 'нет';
    }
    return getenv('GITHUB_ACTIONS') === 'true' ? 'есть' : 'есть (…' . mb_substr($value, -4) . ')';
}

/** Журнал: только время, номер поста ВК, площадка и результат. */
function journal(string $post, string $platform, string $result): void
{
    $file = Ctx::$data . '/journal.log';
    if (is_file($file) && filesize($file) > 2000000) {
        @rename($file, $file . '.old');
    }
    $line = date('Y-m-d H:i:s', now()) . ' | ' . $post . ' | ' . $platform . ' | ' . redact($result) . PHP_EOL;
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    @chmod($file, 0600);
}

// ---------------------------------------------------------------- HTTP

function http(string $method, string $url, array $opt = []): array
{
    if (Ctx::$fakeHttp !== null) {
        return (Ctx::$fakeHttp)($method, $url, $opt);
    }
    $ch = curl_init($url);
    $headers = $opt['headers'] ?? [];
    $o = [
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $opt['timeout'] ?? 90,
        CURLOPT_USERAGENT => 'EKTEN-crosspost/' . APP_VERSION,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ];
    $ca = Ctx::$data . '/ca-bundle.pem';
    // Серверы MAX используют сертификат российского корневого УЦ Минцифры: он доверяется только для них.
    $host = (string)parse_url($url, PHP_URL_HOST);
    $maxCa = Ctx::$data . '/ca-max.pem';
    if (is_file($maxCa) && preg_match('/(^|\.)(max\.ru|oneme\.ru)$/i', $host)) {
        $o[CURLOPT_CAINFO] = $maxCa;
    } elseif (is_file($ca)) {
        $o[CURLOPT_CAINFO] = $ca;
    }
    if ($method === 'POST') {
        $o[CURLOPT_POST] = true;
        if (array_key_exists('json', $opt)) {
            $o[CURLOPT_POSTFIELDS] = json_encode($opt['json'], JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        } else {
            $o[CURLOPT_POSTFIELDS] = $opt['form'] ?? '';
        }
    }
    $fh = null;
    if (isset($opt['save_to'])) {
        $fh = fopen($opt['save_to'], 'wb');
        $o[CURLOPT_FILE] = $fh;
    } else {
        $o[CURLOPT_RETURNTRANSFER] = true;
    }
    $o[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $o);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($fh) {
        fclose($fh);
        $body = '';
    }
    return ['code' => $code, 'body' => is_string($body) ? $body : '', 'error' => $err];
}

// ---------------------------------------------------------------- ВКонтакте

function vk(string $method, array $params): array
{
    $params['v'] = VK_V;
    $params['access_token'] = Ctx::$cfg['VK_SERVICE_KEY'];
    // POST, чтобы ключ не попадал в адрес запроса.
    $r = http('POST', VK_API . $method, ['form' => http_build_query($params)]);
    $j = json_decode($r['body'], true);
    if (!is_array($j)) {
        throw new ApiError('ВК: нет ответа (' . ($r['error'] ?: 'HTTP ' . $r['code']) . ')');
    }
    if (isset($j['error'])) {
        $code = (string)($j['error']['error_code'] ?? '');
        throw new ApiError('ВК: ошибка ' . $code . ' ' . ($j['error']['error_msg'] ?? ''), $code);
    }
    return is_array($j['response'] ?? null) ? $j['response'] : [];
}

function vk_group_id(): int
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    $g = trim((string)Ctx::$cfg['VK_GROUP']);
    $g = preg_replace('~^(https?://)?(m\.)?vk\.(com|ru)/~i', '', $g) ?? $g;
    if (preg_match('/^(club|public)?(\d+)$/', $g, $m)) {
        return $id = (int)$m[2];
    }
    $r = vk('groups.getById', ['group_id' => $g]);
    $groups = $r['groups'] ?? $r;
    if (!isset($groups[0]['id'])) {
        throw new ApiError('ВК: группа «' . $g . '» не найдена');
    }
    return $id = (int)$groups[0]['id'];
}

/** Последние посты сообщества (только от имени сообщества). */
function vk_recent_posts(): array
{
    $r = vk('wall.get', ['owner_id' => -vk_group_id(), 'count' => WALL_COUNT, 'filter' => 'owner']);
    return $r['items'] ?? [];
}

function vk_post(int $postId): ?array
{
    $r = vk('wall.getById', ['posts' => '-' . vk_group_id() . '_' . $postId]);
    $items = $r['items'] ?? $r;
    return isset($items[0]['id']) ? $items[0] : null;
}

/** Внутренние ссылки ВК [club1|Текст] → «Текст», [https://site|Текст] → «Текст (https://site)». */
function vk_clean_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    return preg_replace_callback('/\[([^\[\]|]+)\|([^\[\]]+)\]/u', static function (array $m): string {
        $target = trim($m[1]);
        $label = trim($m[2]);
        if (preg_match('~^(https?://|vk\.(com|ru)/|[\w.-]+\.[a-z]{2,}/)~iu', $target)) {
            $url = preg_match('~^https?://~i', $target) ? $target : 'https://' . $target;
            return $label === $target ? $url : $label . ' (' . $url . ')';
        }
        return $label;
    }, $text) ?? $text;
}

/** Варианты одной картинки ВК, от большей к меньшей: [['url','w','h'], ...]. */
function vk_photo_variants(array $photo): array
{
    $list = [];
    if (!empty($photo['orig_photo']['url'])) {
        $list[] = $photo['orig_photo'];
    }
    foreach ($photo['sizes'] ?? [] as $s) {
        if (!empty($s['url'])) {
            $list[] = $s;
        }
    }
    $out = [];
    foreach ($list as $s) {
        $out[$s['url']] = ['url' => $s['url'], 'w' => (int)($s['width'] ?? 0), 'h' => (int)($s['height'] ?? 0)];
    }
    $out = array_values($out);
    usort($out, static fn($a, $b) => ($b['w'] * $b['h']) <=> ($a['w'] * $a['h']));
    return $out;
}

/**
 * Что публиковать из поста ВК: текст и картинки.
 * Возвращает ['skip' => причина|null, 'text' => string, 'photos' => [[варианты], ...]].
 */
function post_content(array $post): array
{
    $text = vk_clean_text((string)($post['text'] ?? ''));
    $photos = [];
    $extra = [];
    foreach ($post['attachments'] ?? [] as $a) {
        $type = $a['type'] ?? '';
        if ($type === 'photo' && isset($a['photo'])) {
            $v = vk_photo_variants($a['photo']);
            if ($v) {
                $photos[] = $v;
            }
        } elseif ($type === 'link' && !empty($a['link']['url'])) {
            $extra[] = $a['link']['url'];
        } elseif ($type === 'video' && isset($a['video']['owner_id'], $a['video']['id'])) {
            $extra[] = 'Видео: https://vk.com/video' . $a['video']['owner_id'] . '_' . $a['video']['id'];
        }
    }
    if (!empty($post['copy_history'])) {
        $orig = $post['copy_history'][0];
        if (trim($text) === '' && !$photos) {
            return ['skip' => 'репост без своего текста — пропущен', 'text' => '', 'photos' => []];
        }
        if (isset($orig['owner_id'], $orig['id'])) {
            $extra[] = 'Репост: https://vk.com/wall' . $orig['owner_id'] . '_' . $orig['id'];
        }
    }
    foreach ($extra as $line) {
        $url = preg_replace('/^[^:]+: /u', '', $line);
        if (mb_strpos($text, (string)$url) === false) {
            $text = rtrim($text) . "\n\n" . $line;
        }
    }
    $text = trim($text);
    if ($text === '' && !$photos) {
        return ['skip' => 'нечего публиковать (нет текста и картинок)', 'text' => '', 'photos' => []];
    }
    return ['skip' => null, 'text' => $text, 'photos' => $photos];
}

// ---------------------------------------------------------------- Текст

/** Длина в единицах UTF-16 — так считает Telegram; для MAX это запас. */
function u16len(string $s): int
{
    return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
}

/** Делит текст на части не длиннее $limit: по абзацам, затем строкам, предложениям, словам. */
function split_text(string $text, int $limit): array
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    if ($text === '') {
        return [];
    }
    $parts = pack_units(preg_split('/\n{2,}/u', $text) ?: [$text], "\n\n", $limit, 0);
    return array_values(array_filter(array_map('trim', $parts), static fn($p) => $p !== ''));
}

function pack_units(array $units, string $sep, int $limit, int $level): array
{
    $levels = [["\n", '/\n/u'], [' ', '/(?<=[.!?…])\s+/u'], [' ', '/\s+/u']];
    $parts = [];
    $cur = '';
    foreach ($units as $u) {
        if ($level > 0 && trim($u) === '') {
            continue;
        }
        if (u16len($u) > $limit) {
            if ($level < count($levels)) {
                [$subSep, $re] = $levels[$level];
                $sub = pack_units(preg_split($re, $u) ?: [$u], $subSep, $limit, $level + 1);
            } else {
                $sub = hard_cut($u, $limit);
            }
            if ($cur !== '') {
                $parts[] = $cur;
            }
            $cur = (string)array_pop($sub);
            array_push($parts, ...$sub);
            continue;
        }
        $candidate = $cur === '' ? $u : $cur . $sep . $u;
        if (u16len($candidate) <= $limit) {
            $cur = $candidate;
        } else {
            $parts[] = $cur;
            $cur = $u;
        }
    }
    if ($cur !== '') {
        $parts[] = $cur;
    }
    return $parts;
}

/** Последний случай: одно «слово» длиннее лимита (например, огромная ссылка). */
function hard_cut(string $s, int $limit): array
{
    $out = [];
    $cur = '';
    foreach (mb_str_split($s) as $ch) {
        if (u16len($cur . $ch) > $limit) {
            $out[] = $cur;
            $cur = '';
        }
        $cur .= $ch;
    }
    if ($cur !== '') {
        $out[] = $cur;
    }
    return $out;
}

// ---------------------------------------------------------------- План отправки

/** Шаги отправки на площадку: сначала группы картинок, затем части текста. */
function build_plan(array $content, string $platform): array
{
    $chunk = $platform === 'tg' ? TG_ALBUM_MAX : MAX_ATTACH_MAX;
    $limit = $platform === 'tg' ? TG_TEXT_LIMIT : MAX_TEXT_LIMIT;
    $steps = [];
    foreach (array_chunk(array_keys($content['photos']), $chunk) as $idx) {
        $steps[] = ['type' => 'photos', 'idx' => $idx];
    }
    foreach (split_text($content['text'], $limit) as $part) {
        $steps[] = ['type' => 'text', 'text' => $part];
    }
    return $steps;
}

// ---------------------------------------------------------------- Картинки

/** Скачивает подходящий вариант картинки для площадки и возвращает путь к файлу. */
function fetch_photo(array $variants, string $platform): string
{
    static $cache = [];
    $maxBytes = $platform === 'tg' ? TG_PHOTO_MAX_BYTES : MAX_PHOTO_MAX_BYTES;
    $fits = static function (array $v) use ($platform): bool {
        if ($v['w'] <= 0 || $v['h'] <= 0) {
            return true;
        }
        return $platform === 'tg'
            ? ($v['w'] + $v['h'] <= TG_PHOTO_MAX_WH && max($v['w'], $v['h']) <= 20 * min($v['w'], $v['h']))
            : ($v['w'] <= MAX_PHOTO_MAX_SIDE && $v['h'] <= MAX_PHOTO_MAX_SIDE);
    };
    $lastError = 'нет подходящего размера';
    foreach ($variants as $v) {
        if (!$fits($v)) {
            continue;
        }
        $file = $cache[$v['url']] ?? null;
        if ($file === null || !is_file($file)) {
            $dir = Ctx::$data . '/tmp';
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            $file = $dir . '/img_' . md5($v['url']) . '.bin';
            $r = http('GET', $v['url'], ['save_to' => $file, 'timeout' => 120]);
            if ($r['code'] !== 200 || !is_file($file) || filesize($file) === 0) {
                @unlink($file);
                $lastError = 'картинка ВК не скачалась (' . ($r['error'] ?: 'HTTP ' . $r['code']) . ')';
                continue;
            }
            $file = normalize_image($file);
            $cache[$v['url']] = $file;
            Ctx::$tmpFiles[] = $file;
        }
        if (filesize($file) > $maxBytes) {
            $lastError = 'картинка слишком большая';
            continue;
        }
        return $file;
    }
    throw new ApiError($lastError);
}

/** Определяет тип файла; WEBP переводит в JPEG (MAX не принимает WEBP). */
function normalize_image(string $file): string
{
    $mime = image_mime($file);
    if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $img = @imagecreatefromwebp($file);
        if ($img !== false) {
            $jpg = $file . '.jpg';
            imagejpeg($img, $jpg, 92);
            imagedestroy($img);
            @unlink($file);
            return $jpg;
        }
    }
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? 'jpg';
    $named = preg_replace('/\.bin$/', '.' . $ext, $file);
    if ($named !== $file) {
        rename($file, $named);
    }
    return $named;
}

function image_mime(string $file): string
{
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $m = finfo_file($f, $file);
        finfo_close($f);
        if (is_string($m)) {
            return $m;
        }
    }
    return 'image/jpeg';
}

function cleanup_tmp(): void
{
    foreach (Ctx::$tmpFiles as $f) {
        @unlink($f);
    }
    Ctx::$tmpFiles = [];
}

// ---------------------------------------------------------------- Telegram

function tg(string $method, array $form): array
{
    for ($try = 0; $try < 3; $try++) {
        $r = http('POST', TG_API . Ctx::$cfg['TG_BOT_TOKEN'] . '/' . $method, ['form' => $form, 'timeout' => 180]);
        $j = json_decode($r['body'], true);
        if (is_array($j) && !empty($j['ok'])) {
            return is_array($j['result']) ? $j['result'] : ['value' => $j['result']];
        }
        $wait = (int)($j['parameters']['retry_after'] ?? 0);
        if ($r['code'] === 429 && $wait > 0 && $wait <= 60) {
            pause($wait + 1);
            continue;
        }
        $desc = is_array($j) ? (string)($j['description'] ?? '') : ($r['error'] ?: 'HTTP ' . $r['code']);
        throw new ApiError('Telegram: ' . $desc, (string)($j['error_code'] ?? $r['code']));
    }
    throw new ApiError('Telegram: слишком много запросов, повторю позже', '429');
}

function tg_step(array $step, array $content, string $chat): void
{
    if ($step['type'] === 'text') {
        tg('sendMessage', ['chat_id' => $chat, 'text' => $step['text']]);
        return;
    }
    $files = [];
    foreach ($step['idx'] as $i) {
        $files[] = fetch_photo($content['photos'][$i], 'tg');
    }
    if (count($files) === 1) {
        tg('sendPhoto', ['chat_id' => $chat, 'photo' => new CURLFile($files[0], image_mime($files[0]), basename($files[0]))]);
        return;
    }
    $form = ['chat_id' => $chat];
    $media = [];
    foreach ($files as $n => $f) {
        $media[] = ['type' => 'photo', 'media' => 'attach://p' . $n];
        $form['p' . $n] = new CURLFile($f, image_mime($f), basename($f));
    }
    $form['media'] = json_encode($media);
    tg('sendMediaGroup', $form);
}

// ---------------------------------------------------------------- MAX

function max_api(string $method, string $pathOrUrl, array $opt = []): array
{
    $opt['headers'] = array_merge($opt['headers'] ?? [], ['Authorization: ' . Ctx::$cfg['MAX_BOT_TOKEN']]);
    $url = str_starts_with($pathOrUrl, 'https://') ? $pathOrUrl : MAX_API . $pathOrUrl;
    $r = http($method, $url, $opt);
    $j = json_decode($r['body'], true);
    if ($r['code'] >= 200 && $r['code'] < 300 && is_array($j)) {
        return $j;
    }
    $code = is_array($j) ? (string)($j['code'] ?? '') : '';
    $msg = is_array($j) ? (string)($j['message'] ?? '') : ($r['error'] ?: '');
    throw new ApiError(trim('MAX: ' . ($code !== '' ? $code : 'HTTP ' . $r['code']) . ' ' . $msg), $code);
}

/** Загрузка одной картинки: POST /uploads?type=image → загрузка файла по выданной ссылке → токен. */
function max_upload_image(string $file): string
{
    $u = max_api('POST', '/uploads?type=image');
    if (empty($u['url'])) {
        throw new ApiError('MAX: не выдан адрес загрузки картинки');
    }
    $res = max_api('POST', (string)$u['url'], [
        'form' => ['data' => new CURLFile($file, image_mime($file), basename($file))],
        'timeout' => 180,
    ]);
    $token = find_key($res, 'token') ?? ($u['token'] ?? null);
    if (!is_string($token) || $token === '') {
        throw new ApiError('MAX: картинка загружена, но токен не получен');
    }
    return $token;
}

function find_key(array $a, string $key): ?string
{
    foreach ($a as $k => $v) {
        if ($k === $key && is_string($v)) {
            return $v;
        }
        if (is_array($v) && ($found = find_key($v, $key)) !== null) {
            return $found;
        }
    }
    return null;
}

/** Отправка сообщения; если картинки ещё обрабатываются (attachment.not.ready) — ждём и повторяем. */
function max_send(string $chat, array $body): void
{
    $delays = isset($body['attachments']) ? [2, 3, 5, 10, 20, 30] : [0];
    foreach ($delays as $n => $d) {
        pause($d);
        try {
            max_api('POST', '/messages?chat_id=' . rawurlencode($chat), ['json' => $body]);
            return;
        } catch (ApiError $e) {
            if ($e->apiCode !== 'attachment.not.ready' || $n === count($delays) - 1) {
                throw $e;
            }
        }
    }
}

function max_step(array $step, array $content, string $chat): void
{
    if ($step['type'] === 'text') {
        max_send($chat, ['text' => $step['text']]);
        pause(0.6); // не больше 2 сообщений в секунду в один канал
        return;
    }
    $attachments = [];
    foreach ($step['idx'] as $i) {
        $token = max_upload_image(fetch_photo($content['photos'][$i], 'max'));
        $attachments[] = ['type' => 'image', 'payload' => ['token' => $token]];
    }
    max_send($chat, ['attachments' => $attachments]);
    pause(0.6);
}

// ---------------------------------------------------------------- Настройки и состояние

const CONFIG_KEYS = [
    'MODE', 'VK_SERVICE_KEY', 'VK_GROUP',
    'TG_BOT_TOKEN', 'TG_TEST_CHAT', 'TG_MAIN_CHAT',
    'MAX_BOT_TOKEN', 'MAX_TEST_CHAT', 'MAX_MAIN_CHAT',
];

function load_config(): void
{
    $file = Ctx::$base . '/config.ini';
    $raw = [];
    if (is_file($file)) {
        $raw = parse_ini_file($file, false, INI_SCANNER_RAW);
        if ($raw === false) {
            throw new RuntimeException('Файл config.ini не читается: проверьте, что каждая строка вида ИМЯ = значение');
        }
    }
    $cfg = [];
    foreach (CONFIG_KEYS as $k) {
        // Переменные окружения (GitHub Actions Secrets) важнее файла.
        $env = getenv($k);
        $v = trim((string)(($env !== false && trim($env) !== '') ? $env : ($raw[$k] ?? '')));
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $cfg[$k] = trim($v);
    }
    $cfg['MODE'] = strtolower($cfg['MODE']) === 'main' ? 'main' : 'test';
    Ctx::$cfg = $cfg;
    Ctx::$secrets = [$cfg['VK_SERVICE_KEY'], $cfg['TG_BOT_TOKEN'], $cfg['MAX_BOT_TOKEN']];
}

/** Куда публикуем в текущем режиме: ['tg' => chat|'' , 'max' => chat|'']. */
function targets(?string $mode = null): array
{
    $m = strtoupper($mode ?? Ctx::$cfg['MODE']);
    return [
        'tg' => Ctx::$cfg['TG_BOT_TOKEN'] !== '' ? Ctx::$cfg['TG_' . $m . '_CHAT'] : '',
        'max' => Ctx::$cfg['MAX_BOT_TOKEN'] !== '' ? Ctx::$cfg['MAX_' . $m . '_CHAT'] : '',
    ];
}

function platform_name(string $p): string
{
    return $p === 'tg' ? 'Telegram' : 'MAX';
}

function load_state(): array
{
    $file = Ctx::$data . '/state.json';
    if (!is_file($file)) {
        return [];
    }
    $s = json_decode((string)file_get_contents($file), true);
    return is_array($s) ? $s : [];
}

function save_state(array $state): void
{
    $file = Ctx::$data . '/state.json';
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($tmp, 0600);
    rename($tmp, $file);
}

function ensure_dirs(): void
{
    foreach ([Ctx::$data, Ctx::$data . '/tmp'] as $d) {
        if (!is_dir($d)) {
            mkdir($d, 0700, true);
        }
    }
}

// ---------------------------------------------------------------- Обычный запуск

function run_cron(): int
{
    if (is_file(Ctx::$base . '/STOP')) {
        return 0;
    }
    ensure_dirs();
    $lock = fopen(Ctx::$data . '/lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0; // предыдущий запуск ещё работает
    }
    $started = now();
    try {
        load_config();
        $targets = targets();
        if ($targets['tg'] === '' && $targets['max'] === '') {
            throw new RuntimeException('не заполнены каналы для режима ' . Ctx::$cfg['MODE']);
        }
        $state = load_state();
        $recent = vk_recent_posts();
        $gid = vk_group_id();

        // Первый запуск, смена режима или группы: старые посты не копируем.
        if (($state['mode'] ?? null) !== Ctx::$cfg['MODE'] || ($state['group'] ?? null) !== $gid) {
            $ids = array_map(static fn($p) => (int)$p['id'], $recent);
            $state = [
                'mode' => Ctx::$cfg['MODE'],
                'group' => $gid,
                'started_at' => $started,
                'initial_ids' => $ids,
                'posts' => [],
            ];
            save_state($state);
            journal('-', '-', 'запуск в режиме ' . Ctx::$cfg['MODE'] . ': уже опубликованные посты пропущены (последний ' . ($ids ? max($ids) : 0) . ')');
            return 0;
        }

        // Новые посты: опубликованы после запуска и ещё не встречались.
        $byId = [];
        foreach ($recent as $p) {
            $byId[(int)$p['id']] = $p;
        }
        ksort($byId);
        foreach ($byId as $id => $p) {
            if (isset($state['posts'][(string)$id]) || in_array($id, $state['initial_ids'], true)) {
                continue;
            }
            if ((int)($p['date'] ?? 0) <= (int)$state['started_at']) {
                continue;
            }
            $entry = ['seen' => now()];
            foreach ($targets as $platform => $chat) {
                $entry[$platform] = $chat === ''
                    ? ['status' => 'off']
                    : ['status' => 'pending', 'step' => 0, 'attempts' => 0, 'next' => 0];
            }
            $state['posts'][(string)$id] = $entry;
            save_state($state);
        }

        // Публикация и повторы.
        $ids = array_keys($state['posts']);
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $key) {
            if (now() - $started > RUN_TIME_BUDGET) {
                break;
            }
            $entry = $state['posts'][$key];
            $due = [];
            foreach (['tg', 'max'] as $platform) {
                $st = $entry[$platform] ?? ['status' => 'off'];
                if ($st['status'] === 'pending' && $st['next'] <= now()) {
                    $due[] = $platform;
                }
            }
            if (!$due) {
                continue;
            }
            $post = $byId[(int)$key] ?? vk_post((int)$key);
            $content = $post === null
                ? ['skip' => 'пост удалён в ВК', 'text' => '', 'photos' => []]
                : post_content($post);
            foreach ($due as $platform) {
                $state['posts'][$key][$platform] = publish_to(
                    $platform, $targets[$platform], (int)$key, $content, $state['posts'][$key][$platform],
                    $entry['seen'], $state
                );
                save_state($state);
            }
        }
        return 0;
    } catch (Throwable $e) {
        journal('-', '-', 'ошибка запуска: ' . $e->getMessage());
        return 1;
    } finally {
        cleanup_tmp();
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Публикует пост на одну площадку, начиная с первого неотправленного шага.
 * Каждый выполненный шаг сразу сохраняется, поэтому при повторе уже отправленное не дублируется.
 */
function publish_to(string $platform, string $chat, int $postId, array $content, array $st, int $seen, array &$state): array
{
    $name = platform_name($platform);
    $label = 'пост ВК ' . $postId;
    if ($chat === '') {
        return ['status' => 'off'];
    }
    if ($content['skip'] !== null) {
        journal($label, $name, 'пропущен: ' . $content['skip']);
        return ['status' => 'skipped'];
    }
    $plan = build_plan($content, $platform);
    try {
        for ($i = (int)$st['step']; $i < count($plan); $i++) {
            $platform === 'tg' ? tg_step($plan[$i], $content, $chat) : max_step($plan[$i], $content, $chat);
            $st['step'] = $i + 1;
            $state['posts'][(string)$postId][$platform] = $st;
            save_state($state);
            pause(1);
        }
        $photos = count($content['photos']);
        journal($label, $name, 'опубликовано (картинок: ' . $photos . ', сообщений: ' . count($plan) . ')');
        return ['status' => 'done', 'step' => count($plan), 'at' => now()];
    } catch (Throwable $e) {
        $st['attempts'] = (int)$st['attempts'] + 1;
        if (now() - $seen > GIVE_UP_AFTER) {
            $st['status'] = 'failed';
            journal($label, $name, 'не удалось за 48 ч, повторы остановлены (--retry=' . $postId . '): ' . $e->getMessage());
        } else {
            $wait = BACKOFF_MIN[min($st['attempts'] - 1, count(BACKOFF_MIN) - 1)];
            $st['next'] = now() + $wait * 60;
            journal($label, $name, 'ошибка, повтор через ' . $wait . ' мин: ' . $e->getMessage());
        }
        return $st;
    }
}

// ---------------------------------------------------------------- Служебные команды

function run_check(): int
{
    load_config();
    ensure_dirs();
    $c = Ctx::$cfg;
    say('ЭкТен — копирование постов, версия ' . APP_VERSION . '. Проверка без публикации.');
    say('Режим: ' . $c['MODE'] . ($c['MODE'] === 'test' ? ' (публикация только в тестовые каналы)' : ' (НАСТОЯЩИЕ каналы ЭкТен)'));
    say('');
    say('Файл настроек:');
    foreach (['VK_SERVICE_KEY', 'TG_BOT_TOKEN', 'MAX_BOT_TOKEN'] as $k) {
        say('  ' . str_pad($k, 16) . mask($c[$k]));
    }
    foreach (['VK_GROUP', 'TG_TEST_CHAT', 'TG_MAIN_CHAT', 'MAX_TEST_CHAT', 'MAX_MAIN_CHAT'] as $k) {
        say('  ' . str_pad($k, 16) . ($c[$k] === '' ? 'нет' : $c[$k]));
    }
    if (is_file(Ctx::$base . '/config.ini')) {
        $perm = substr(sprintf('%o', fileperms(Ctx::$base . '/config.ini')), -3);
        say('  права config.ini: ' . $perm . ($perm === '600' ? ' (только владелец — хорошо)' : ' (нужно 600)'));
    } else {
        say('  config.ini нет — настройки берутся из переменных окружения');
    }
    $ok = true;

    say('');
    say('ВКонтакте:');
    try {
        if ($c['VK_SERVICE_KEY'] === '' || $c['VK_GROUP'] === '') {
            throw new RuntimeException('не заполнены VK_SERVICE_KEY и/или VK_GROUP');
        }
        $gid = vk_group_id();
        $posts = vk_recent_posts();
        $last = $posts ? max(array_map(static fn($p) => (int)$p['id'], $posts)) : 0;
        say('  стена читается: группа ' . $gid . ', последний пост №' . $last);
    } catch (Throwable $e) {
        $ok = false;
        say('  ОШИБКА: ' . redact($e->getMessage()));
        if ($e instanceof ApiError && in_array($e->apiCode, ['5', '15', '27', '28'], true)) {
            say('  Подсказка: для чтения стены нужен СЕРВИСНЫЙ ключ приложения VK ID, ключ сообщества не подходит.');
        }
    }

    say('');
    say('Telegram:');
    if ($c['TG_BOT_TOKEN'] === '') {
        say('  токен не задан — Telegram выключен');
    } else {
        try {
            $me = tg('getMe', []);
            say('  бот: @' . ($me['username'] ?? '?'));
            foreach (['TEST', 'MAIN'] as $m) {
                $chat = $c['TG_' . $m . '_CHAT'];
                if ($chat === '') {
                    say('  ' . $m . ': канал не задан');
                    continue;
                }
                try {
                    $info = tg('getChat', ['chat_id' => $chat]);
                    $member = tg('getChatMember', ['chat_id' => $chat, 'user_id' => (string)$me['id']]);
                    $canPost = ($member['status'] ?? '') === 'creator' || !empty($member['can_post_messages']);
                    say('  ' . $m . ': «' . ($info['title'] ?? '?') . '» (' . ($info['type'] ?? '?') . '), бот: '
                        . ($member['status'] ?? '?') . ', может публиковать: ' . ($canPost ? 'да' : 'НЕТ'));
                    $ok = $ok && $canPost;
                } catch (Throwable $e) {
                    $ok = false;
                    say('  ' . $m . ': ОШИБКА ' . redact($e->getMessage()));
                }
            }
        } catch (Throwable $e) {
            $ok = false;
            say('  ОШИБКА: ' . redact($e->getMessage()));
        }
    }

    say('');
    say('MAX:');
    if ($c['MAX_BOT_TOKEN'] === '') {
        say('  токен не задан — MAX выключен');
    } else {
        try {
            $me = max_api('GET', '/me');
            say('  бот: ' . ($me['name'] ?? $me['first_name'] ?? '?') . (isset($me['username']) ? ' (@' . $me['username'] . ')' : ''));
            foreach (['TEST', 'MAIN'] as $m) {
                $chat = $c['MAX_' . $m . '_CHAT'];
                if ($chat === '') {
                    say('  ' . $m . ': канал не задан');
                    continue;
                }
                try {
                    $info = max_api('GET', '/chats/' . rawurlencode($chat));
                    $member = max_api('GET', '/chats/' . rawurlencode($chat) . '/members/me');
                    $perms = $member['permissions'] ?? [];
                    say('  ' . $m . ': «' . ($info['title'] ?? '?') . '» (' . ($info['type'] ?? '?') . '), бот администратор: '
                        . (!empty($member['is_admin']) ? 'да' : 'НЕТ') . ', права: ' . (is_array($perms) ? implode(', ', $perms) : '?'));
                    $ok = $ok && !empty($member['is_admin']);
                } catch (Throwable $e) {
                    $ok = false;
                    say('  ' . $m . ': ОШИБКА ' . redact($e->getMessage()));
                }
            }
        } catch (Throwable $e) {
            $ok = false;
            say('  ОШИБКА: ' . redact($e->getMessage()));
        }
    }
    $need = ['VK_SERVICE_KEY', 'VK_GROUP', 'TG_BOT_TOKEN', 'MAX_BOT_TOKEN', 'TG_' . strtoupper($c['MODE']) . '_CHAT', 'MAX_' . strtoupper($c['MODE']) . '_CHAT'];
    $missing = array_values(array_filter($need, static fn($k) => $c[$k] === ''));
    say('');
    if ($missing) {
        $msg = 'Не заданы настройки: ' . implode(', ', $missing) . ' (значения секретов не выводятся).';
        say($msg);
        if (getenv('GITHUB_ACTIONS') === 'true') {
            echo '::error title=crosspost check::' . $msg . PHP_EOL;
        }
    }
    say($ok ? 'ИТОГ: всё готово.' : 'ИТОГ: есть замечания (см. выше).');
    return $ok ? 0 : 1;
}

function run_dry(?int $postId): int
{
    load_config();
    ensure_dirs();
    say('ПРОБНЫЙ ПРОГОН: ничего не публикуется, состояние не меняется.');
    $posts = $postId !== null ? array_filter([vk_post($postId)]) : array_slice(vk_recent_posts(), 0, 3);
    foreach ($posts as $post) {
        $content = post_content($post);
        say('');
        say('=== Пост ВК №' . $post['id'] . ' от ' . date('d.m.Y H:i', (int)$post['date']));
        if ($content['skip'] !== null) {
            say('  будет пропущен: ' . $content['skip']);
            continue;
        }
        foreach (['tg', 'max'] as $platform) {
            say('  ' . platform_name($platform) . ':');
            foreach (build_plan($content, $platform) as $n => $step) {
                if ($step['type'] === 'photos') {
                    $sizes = array_map(static function (int $i) use ($content, $platform): string {
                        $file = fetch_photo($content['photos'][$i], $platform);
                        return round(filesize($file) / 1048576, 1) . ' МБ';
                    }, $step['idx']);
                    $kind = count($step['idx']) === 1 ? 'фото' : 'альбом из ' . count($step['idx']);
                    say('    ' . ($n + 1) . ') ' . $kind . ' [' . implode(', ', $sizes) . ']');
                } else {
                    $preview = mb_substr(str_replace("\n", ' ⏎ ', $step['text']), 0, 90);
                    say('    ' . ($n + 1) . ') текст ' . u16len($step['text']) . ' знаков: «' . $preview . '…»');
                }
            }
        }
    }
    cleanup_tmp();
    return 0;
}

function run_test_post(int $postId): int
{
    load_config();
    ensure_dirs();
    $targets = targets('test');
    $post = vk_post($postId);
    if ($post === null) {
        say('Пост ВК №' . $postId . ' не найден.');
        return 1;
    }
    $content = post_content($post);
    $ok = true;
    $dummy = ['posts' => []];
    foreach ($targets as $platform => $chat) {
        if ($chat === '') {
            say(platform_name($platform) . ': тестовый канал не задан — пропуск');
            continue;
        }
        $st = publish_to($platform, $chat, $postId, $content, ['status' => 'pending', 'step' => 0, 'attempts' => 0], now(), $dummy);
        say(platform_name($platform) . ' (тестовый канал): ' . $st['status']);
        $ok = $ok && $st['status'] === 'done';
    }
    cleanup_tmp();
    return $ok ? 0 : 1;
}

function run_status(): int
{
    load_config();
    $s = load_state();
    say('Режим в настройках: ' . Ctx::$cfg['MODE'] . (is_file(Ctx::$base . '/STOP') ? ' — ОСТАНОВЛЕНО файлом STOP' : ''));
    if (!$s) {
        say('Ещё не запускалась.');
        return 0;
    }
    say('Работает с ' . date('d.m.Y H:i', (int)$s['started_at']) . ' в режиме ' . $s['mode'] . ', группа ' . $s['group']);
    $count = [];
    foreach ($s['posts'] as $id => $e) {
        foreach (['tg', 'max'] as $p) {
            $st = $e[$p]['status'] ?? 'off';
            $count[$p][$st] = ($count[$p][$st] ?? 0) + 1;
            if (in_array($st, ['pending', 'failed'], true)) {
                say('  пост ВК ' . $id . ' → ' . platform_name($p) . ': ' . $st . ', попыток ' . ($e[$p]['attempts'] ?? 0));
            }
        }
    }
    foreach ($count as $p => $c) {
        $parts = [];
        foreach ($c as $k => $v) {
            $parts[] = $k . ' ' . $v;
        }
        say(platform_name($p) . ': ' . implode(', ', $parts));
    }
    return 0;
}

function run_retry(int $postId): int
{
    load_config();
    $s = load_state();
    $key = (string)$postId;
    if (!isset($s['posts'][$key])) {
        say('Пост ' . $postId . ' не найден в состоянии.');
        return 1;
    }
    foreach (['tg', 'max'] as $p) {
        if (($s['posts'][$key][$p]['status'] ?? '') === 'failed') {
            $s['posts'][$key][$p]['status'] = 'pending';
            $s['posts'][$key][$p]['next'] = 0;
        }
    }
    $s['posts'][$key]['seen'] = now();
    save_state($s);
    say('Пост ' . $postId . ' поставлен на повтор — уйдёт при следующем запуске.');
    return 0;
}

// ---------------------------------------------------------------- Накопленные посты

/** Вся стена сообщества от старых к новым (только чтение). */
function vk_all_posts(): array
{
    $all = [];
    for ($offset = 0; $offset < 5000; $offset += 100) {
        $r = vk('wall.get', ['owner_id' => -vk_group_id(), 'count' => 100, 'offset' => $offset, 'filter' => 'owner']);
        $items = $r['items'] ?? [];
        foreach ($items as $p) {
            $all[(int)$p['id']] = $p;
        }
        if (count($items) < 100) {
            break;
        }
        pause(0.4);
    }
    ksort($all);
    return $all;
}

const TG_PUBLIC_USER = 'ekten_ru';
const MAX_PUBLIC_LINK = 'channel_ekten';

/** Ключ сопоставления: первые 60 букв и цифр текста без регистра, ссылок и знаков. */
function match_key(string $text): string
{
    $text = preg_replace('~https?://\S+~u', '', $text) ?? $text;
    $t = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text)) ?? '';
    return mb_substr($t, 0, 60);
}

/** Все каналы MAX, где бот состоит: id, тип, название, ссылка. */
function max_chats(): array
{
    $out = [];
    $marker = null;
    for ($i = 0; $i < 10; $i++) {
        $r = max_api('GET', '/chats?count=100' . ($marker ? '&marker=' . rawurlencode((string)$marker) : ''));
        foreach ($r['chats'] ?? [] as $c) {
            $out[] = $c;
        }
        $marker = $r['marker'] ?? null;
        if (!$marker) {
            break;
        }
    }
    return $out;
}

/** Основной канал MAX: из настроек, иначе по публичной ссылке среди каналов бота. */
function max_main_chat(): string
{
    if (Ctx::$cfg['MAX_MAIN_CHAT'] !== '') {
        return Ctx::$cfg['MAX_MAIN_CHAT'];
    }
    foreach (max_chats() as $c) {
        if (stripos((string)($c['link'] ?? ''), MAX_PUBLIC_LINK) !== false) {
            return (string)$c['chat_id'];
        }
    }
    return '';
}

/** Только чтение: доступ к ВК, Telegram и MAX, каналы, права. Ничего не отправляет. */
function run_probe(): int
{
    load_config();
    ensure_dirs();
    $c = Ctx::$cfg;
    say('ПРОВЕРКА ДОСТУПА (только чтение). Режим: ' . $c['MODE']);
    foreach (['VK_SERVICE_KEY', 'TG_BOT_TOKEN', 'MAX_BOT_TOKEN'] as $k) {
        say('  ' . str_pad($k, 16) . mask($c[$k]));
    }
    say('');
    say('ВК:');
    try {
        $r = vk('wall.get', ['owner_id' => -vk_group_id(), 'count' => 1, 'filter' => 'owner']);
        say('  PASS: стена читается, группа ' . vk_group_id() . ', постов от имени сообщества: ' . (int)($r['count'] ?? 0));
    } catch (Throwable $e) {
        say('  FAIL: ' . redact($e->getMessage()));
    }
    say('Telegram:');
    try {
        $me = tg('getMe', []);
        say('  PASS: бот @' . ($me['username'] ?? '?'));
        foreach (['MAIN', 'TEST'] as $m) {
            $chat = $c['TG_' . $m . '_CHAT'];
            if ($chat === '') {
                say('  ' . $m . ': канал не задан');
                continue;
            }
            try {
                $info = tg('getChat', ['chat_id' => $chat]);
                $mem = tg('getChatMember', ['chat_id' => $chat, 'user_id' => (string)$me['id']]);
                say('  ' . $m . ': «' . ($info['title'] ?? '?') . '» (' . ($info['type'] ?? '?') . '), статус бота: ' . ($mem['status'] ?? '?')
                    . ', может публиковать: ' . ((($mem['status'] ?? '') === 'creator' || !empty($mem['can_post_messages'])) ? 'да' : 'нет'));
            } catch (Throwable $e) {
                say('  ' . $m . ': FAIL ' . redact($e->getMessage()));
            }
        }
    } catch (Throwable $e) {
        say('  FAIL: ' . redact($e->getMessage()));
    }
    say('MAX:');
    try {
        $me = max_api('GET', '/me');
        say('  PASS: бот ' . ($me['name'] ?? $me['first_name'] ?? '?') . (isset($me['username']) ? ' (@' . $me['username'] . ')' : ''));
        $chats = max_chats();
        say('  каналов и чатов, где бот состоит: ' . count($chats));
        foreach ($chats as $ch) {
            say('    chat_id ' . ($ch['chat_id'] ?? '?') . '  ' . ($ch['type'] ?? '?') . '  «' . ($ch['title'] ?? '?') . '»  ссылка: ' . ($ch['link'] ?? '-'));
        }
        $main = max_main_chat();
        say('  основной канал ЭкТен в MAX: ' . ($main !== '' ? 'chat_id ' . $main : 'НЕ НАЙДЕН среди каналов бота'));
    } catch (Throwable $e) {
        say('  FAIL: ' . redact($e->getMessage()));
    }
    return 0;
}

/** Публичная лента Telegram (t.me/s/…): id, дата, наличие картинки, ключ текста. Возвращает null, если лента недоступна. */
function tg_public_history(string &$why): ?array
{
    $msgs = [];
    $before = null;
    for ($page = 0; $page < 120; $page++) {
        $r = http('GET', 'https://t.me/s/' . TG_PUBLIC_USER . ($before ? '?before=' . $before : ''), ['timeout' => 40]);
        if ($r['code'] !== 200 || $r['body'] === '') {
            if ($page === 0) {
                $why = 't.me/s/' . TG_PUBLIC_USER . ': HTTP ' . $r['code'] . ($r['error'] ? ' ' . $r['error'] : '') . ', пустой ответ';
                return null;
            }
            break;
        }
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $r['body']);
        $xp = new DOMXPath($dom);
        $wraps = $xp->query("//div[contains(@class,'tgme_widget_message_wrap')]");
        if (!$wraps || $wraps->length === 0) {
            if ($page === 0) {
                $why = 't.me/s/' . TG_PUBLIC_USER . ': страница есть, но сообщений в ней нет (лента отключена или канал без публичного просмотра)';
                return null;
            }
            break;
        }
        $minId = null;
        $new = 0;
        foreach ($wraps as $w) {
            $post = $xp->query(".//div[@data-post]", $w)->item(0);
            if (!$post) {
                continue;
            }
            $id = (int)substr((string)strrchr((string)$post->getAttribute('data-post'), '/'), 1);
            $minId = $minId === null ? $id : min($minId, $id);
            if (isset($msgs[$id])) {
                continue;
            }
            $new++;
            $textNode = $xp->query(".//div[contains(@class,'tgme_widget_message_text')]", $w)->item(0);
            $time = $xp->query(".//time", $w)->item(0);
            $msgs[$id] = [
                'id' => $id,
                'ts' => $time ? (int)strtotime((string)$time->getAttribute('datetime')) : 0,
                'photo' => $xp->query(".//a[contains(@class,'tgme_widget_message_photo_wrap')]", $w)->length > 0,
                'text' => $textNode ? trim((string)$textNode->textContent) : '',
            ];
        }
        if ($new === 0 || $minId === null || $minId <= 1) {
            break;
        }
        $before = $minId;
        pause(0.7);
    }
    ksort($msgs);
    return array_values($msgs);
}

/** История канала MAX через API бота (GET /messages). */
function max_history(string $chat): array
{
    $msgs = [];
    $to = null;
    for ($page = 0; $page < 150; $page++) {
        $r = max_api('GET', '/messages?chat_id=' . rawurlencode($chat) . '&count=100' . ($to ? '&to=' . $to : ''));
        $items = $r['messages'] ?? [];
        if (!$items) {
            break;
        }
        $oldest = null;
        $new = 0;
        foreach ($items as $m) {
            $ts = (int)($m['timestamp'] ?? 0);
            $mid = (string)($m['body']['mid'] ?? $ts);
            $oldest = $oldest === null ? $ts : min($oldest, $ts);
            if (isset($msgs[$mid])) {
                continue;
            }
            $new++;
            $img = 0;
            foreach ($m['body']['attachments'] ?? [] as $a) {
                if (($a['type'] ?? '') === 'image') {
                    $img++;
                }
            }
            $msgs[$mid] = ['ts' => (int)($ts / 1000), 'photos' => $img, 'text' => trim((string)($m['body']['text'] ?? ''))];
        }
        if ($new === 0 || $oldest === null || count($items) < 100) {
            break;
        }
        $to = $oldest - 1;
        pause(0.5);
    }
    $list = array_values($msgs);
    usort($list, static fn($a, $b) => $a['ts'] <=> $b['ts']);
    return $list;
}

/**
 * Сверка поста ВК с историей площадки. $hist — список сообщений по времени: ['text','photos'(int),'ts'].
 * Картинки поста считаются как сообщения без текста, идущие непосредственно перед текстовым сообщением.
 */
function reconcile_post(array $content, array $hist, array $index): array
{
    $expectedPhotos = count($content['photos']);
    $key = match_key($content['text']);
    if ($key === '') {
        return ['state' => 'не определено (пост без текста: сопоставить по картинкам нельзя)', 'missing_photos' => 0, 'dups' => 0];
    }
    $hits = $index[$key] ?? [];
    if (!$hits) {
        return ['state' => 'нет', 'missing_photos' => $expectedPhotos, 'dups' => 0];
    }
    $pos = $hits[0];
    $have = 0;
    for ($i = $pos - 1; $i >= 0 && $hist[$i]['text'] === '' && $hist[$i]['photos'] > 0; $i--) {
        $have += $hist[$i]['photos'];
    }
    $state = 'есть';
    $missing = max(0, $expectedPhotos - $have);
    if ($missing > 0) {
        $state = 'есть, не хватает фото';
    }
    $plain = static fn(string $s): int => mb_strlen(preg_replace('~https?://\S+|\s+~u', '', $s) ?? $s);
    if ($plain($content['text']) <= 3800 && $plain($hist[$pos]['text']) < 0.9 * $plain($content['text'])) {
        $state .= '; текст короче оригинала';
    }
    return ['state' => $state, 'missing_photos' => $missing, 'dups' => count($hits) - 1];
}

/** Аудит: стена ВК против фактической истории Telegram (публичная лента) и MAX (API). Ничего не публикует и не меняет. */
function run_audit(): int
{
    load_config();
    ensure_dirs();
    $posts = vk_all_posts();
    say('СВЕРКА (только чтение). Постов на стене ВК: ' . count($posts));
    $contents = [];
    foreach ($posts as $id => $p) {
        $contents[$id] = post_content($p);
    }
    $todo = array_filter($contents, static fn($c) => $c['skip'] === null);
    say('К копированию: ' . count($todo) . ', фотографий в них: ' . array_sum(array_map(static fn($c) => count($c['photos']), $todo)));

    $platforms = [];
    $why = '';
    $tg = tg_public_history($why);
    if ($tg === null) {
        say('Telegram: история НЕДОСТУПНА — ' . $why . '. Bot API старую историю канала не отдаёт, результат не придумываю.');
    } else {
        $hist = array_map(static fn($m) => ['text' => $m['text'], 'photos' => $m['photo'] ? 1 : 0, 'ts' => $m['ts']], $tg);
        say('Telegram: прочитано сообщений публичной ленты: ' . count($hist) . ($hist ? ', с ' . date('d.m.Y', $hist[0]['ts']) : ''));
        $platforms['tg'] = $hist;
    }
    try {
        $chat = Ctx::$cfg['MAX_BOT_TOKEN'] !== '' ? max_main_chat() : '';
        if ($chat === '') {
            say('MAX: канал не определён — история недоступна.');
        } else {
            $hist = max_history($chat);
            say('MAX: chat_id ' . $chat . ', прочитано сообщений: ' . count($hist) . ($hist ? ', с ' . date('d.m.Y', $hist[0]['ts']) : ''));
            $platforms['max'] = $hist;
        }
    } catch (Throwable $e) {
        say('MAX: история НЕДОСТУПНА — ' . redact($e->getMessage()));
    }

    $rows = [];
    $sum = [];
    foreach ($platforms as $pl => $hist) {
        $index = [];
        foreach ($hist as $i => $m) {
            if ($m['text'] !== '') {
                $index[match_key($m['text'])][] = $i;
            }
        }
        $sum[$pl] = ['missing_posts' => 0, 'missing_photos' => 0, 'partial' => 0, 'unknown' => 0, 'dups' => 0, 'ok' => 0];
        foreach ($todo as $id => $content) {
            $r = reconcile_post($content, $hist, $index);
            $rows[$id][$pl] = $r;
            if ($r['state'] === 'нет') {
                $sum[$pl]['missing_posts']++;
                $sum[$pl]['missing_photos'] += $r['missing_photos'];
            } elseif (str_starts_with($r['state'], 'не определено')) {
                $sum[$pl]['unknown']++;
            } else {
                $r['state'] === 'есть' ? $sum[$pl]['ok']++ : $sum[$pl]['partial']++;
                $sum[$pl]['missing_photos'] += $r['missing_photos'];
            }
            $sum[$pl]['dups'] += $r['dups'];
        }
    }
    say('');
    foreach ($sum as $pl => $s) {
        say(platform_name($pl) . ': полностью есть ' . $s['ok'] . ', нет совсем ' . $s['missing_posts'] . ', неполные ' . $s['partial']
            . ', не определено ' . $s['unknown'] . ', дублей ' . $s['dups'] . ', не хватает фотографий ' . $s['missing_photos']);
    }
    say('');
    foreach ($todo as $id => $content) {
        $line = sprintf('  №%d  %s  фото %d  текст %d', $id, date('Y-m-d', (int)$posts[$id]['date']), count($content['photos']), mb_strlen($content['text']));
        foreach ($platforms as $pl => $_) {
            $line .= '  ' . strtoupper($pl) . ': ' . $rows[$id][$pl]['state'];
        }
        say($line);
    }
    file_put_contents(Ctx::$data . '/audit.json', json_encode(['summary' => $sum, 'posts' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    say('');
    say('Публикаций не было, состояние не менялось.');
    return 0;
}

/** Ставит накопленные посты в очередь на публикацию (уйдут при ближайшем запуске --cron). Требует явного списка. */
function run_enqueue(string $arg, string $platforms): int
{
    load_config();
    ensure_dirs();
    $state = load_state();
    if (!$state) {
        say('Сначала нужен хотя бы один обычный запуск (--cron).');
        return 1;
    }
    $wall = vk_all_posts();
    $ids = [];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) {
        $since = strtotime($arg . ' 00:00:00');
        foreach ($wall as $id => $p) {
            if ((int)$p['date'] >= $since) {
                $ids[] = $id;
            }
        }
    } else {
        foreach (explode(',', $arg) as $part) {
            if (ctype_digit(trim($part)) && isset($wall[(int)$part])) {
                $ids[] = (int)$part;
            }
        }
    }
    $want = array_filter(explode(',', $platforms !== '' ? $platforms : 'tg,max'), static fn($x) => in_array($x, ['tg', 'max'], true));
    $targets = targets();
    $added = 0;
    foreach ($ids as $id) {
        $key = (string)$id;
        $entry = $state['posts'][$key] ?? ['seen' => now()];
        foreach ($want as $platform) {
            if (($entry[$platform]['status'] ?? '') === 'done' || $targets[$platform] === '') {
                continue;
            }
            $entry[$platform] = ['status' => 'pending', 'step' => 0, 'attempts' => 0, 'next' => 0];
            $added++;
        }
        $entry['seen'] = now();
        $state['posts'][$key] = $entry;
    }
    save_state($state);
    foreach ($ids as $id) {
        journal('пост ВК ' . $id, '-', 'поставлен в очередь вручную (' . implode(',', $want) . ')');
    }
    say('Постов в очереди: ' . count($ids) . ', заданий на площадки: ' . $added . '. Уйдут при ближайшем --cron.');
    return 0;
}

// ---------------------------------------------------------------- Самопроверка

function run_selftest(): int
{
    $fails = 0;
    $check = static function (bool $cond, string $name) use (&$fails): void {
        echo ($cond ? '  ок   ' : '  СБОЙ ') . $name . PHP_EOL;
        if (!$cond) {
            $fails++;
        }
    };
    echo 'Самопроверка (без интернета)' . PHP_EOL;

    // 1. Деление текста.
    $para = static fn(int $n) => trim(str_repeat('Слово ', $n));
    $text = implode("\n\n", [$para(300), $para(500), $para(400), $para(200)]);
    $parts = split_text($text, TG_TEXT_LIMIT);
    $check(count($parts) > 1 && max(array_map('u16len', $parts)) <= TG_TEXT_LIMIT, 'длинный текст делится на части до 4096 знаков');
    $check(preg_replace('/\s+/u', ' ', implode(' ', $parts)) === preg_replace('/\s+/u', ' ', $text), 'при делении ничего не теряется');
    $noBroken = true;
    foreach ($parts as $p) {
        $noBroken = $noBroken && preg_match('/^Слово/u', $p) && preg_match('/Слово$/u', $p);
    }
    $check($noBroken, 'слова не разрываются');
    $check(split_text("Короткий текст", TG_TEXT_LIMIT) === ['Короткий текст'], 'короткий текст — одно сообщение');
    $check(count(split_text(str_repeat('😀', 2100), TG_TEXT_LIMIT)) === 2, 'эмодзи считаются как в Telegram (2 единицы)');
    $maxParts = split_text($text, MAX_TEXT_LIMIT);
    $check(max(array_map('u16len', $maxParts)) <= MAX_TEXT_LIMIT, 'для MAX части до 4000 знаков');

    // 2. Ссылки ВК.
    $check(vk_clean_text('Привет [club123|ЭкТен] и [https://ekten.ru|сайт]') === 'Привет ЭкТен и сайт (https://ekten.ru)', 'внутренние ссылки ВК переводятся в текст');

    // 3. Полный цикл на поддельных ответах.
    $dir = sys_get_temp_dir() . '/ekten_selftest_' . getmypid();
    @mkdir($dir, 0700, true);
    file_put_contents($dir . '/config.ini', "MODE = test\nVK_SERVICE_KEY = vkSECRET\nVK_GROUP = 555\nTG_BOT_TOKEN = 1:TGSECRET\nTG_TEST_CHAT = -1001\nMAX_BOT_TOKEN = MAXSECRET\nMAX_TEST_CHAT = -77\n");
    Ctx::$base = $dir;
    Ctx::$data = $dir . '/data';
    $clock = 1800000000;
    Ctx::$clock = static function () use (&$clock) {
        return $clock;
    };
    $wall = [];
    $calls = [];
    $failMax = false;
    $failTgText = 0;
    $photo = static fn(int $n) => ['type' => 'photo', 'photo' => ['sizes' => [
        ['type' => 'x', 'url' => "https://sun.userapi.com/p{$n}_x.jpg", 'width' => 604, 'height' => 400],
        ['type' => 'w', 'url' => "https://sun.userapi.com/p{$n}_w.jpg", 'width' => 2560, 'height' => 1700],
    ]]];
    Ctx::$fakeHttp = static function (string $method, string $url, array $opt) use (&$wall, &$calls, &$failMax, &$failTgText): array {
        $json = static fn($v, int $code = 200) => ['code' => $code, 'body' => json_encode($v), 'error' => ''];
        if (str_starts_with($url, VK_API)) {
            parse_str((string)$opt['form'], $q);
            if (str_ends_with($url, 'wall.get')) {
                return $json(['response' => ['count' => count($wall), 'items' => array_values(array_reverse($wall))]]);
            }
            $pid = (int)substr((string)$q['posts'], strrpos((string)$q['posts'], '_') + 1);
            return $json(['response' => ['items' => isset($wall[$pid]) ? [$wall[$pid]] : []]]);
        }
        if (isset($opt['save_to'])) {
            file_put_contents($opt['save_to'], "\xFF\xD8\xFF\xE0fakejpeg" . str_repeat('x', 100));
            return ['code' => 200, 'body' => '', 'error' => ''];
        }
        if (str_starts_with($url, TG_API)) {
            $m = substr($url, strrpos($url, '/') + 1);
            if ($m === 'sendMessage' && $failTgText > 0) {
                $failTgText--;
                return $json(['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway'], 502);
            }
            $n = $m === 'sendMediaGroup' ? count(json_decode($opt['form']['media'], true)) : ($m === 'sendPhoto' ? 1 : 0);
            $calls[] = 'tg:' . $m . ($n ? ':' . $n : '');
            return $json(['ok' => true, 'result' => ['message_id' => count($calls)]]);
        }
        if ($failMax) {
            return $json(['code' => 'service.unavailable', 'message' => 'temporary'], 503);
        }
        if (str_contains($url, '/uploads?type=image')) {
            return $json(['url' => 'https://iu.oneme.ru/uploadImage?r=fake']);
        }
        if (str_starts_with($url, 'https://iu.oneme.ru/')) {
            return $json(['photos' => ['p1' => ['token' => 'tok' . count($calls)]]]);
        }
        if (str_contains($url, '/messages?chat_id=')) {
            $calls[] = 'max:' . (isset($opt['json']['attachments']) ? 'images:' . count($opt['json']['attachments']) : 'text');
            return $json(['message' => ['body' => ['mid' => 'm' . count($calls)]]]);
        }
        return $json(['error' => 'unexpected ' . $url], 404);
    };
    $add = static function (int $id, string $text, int $photos) use (&$wall, &$clock, $photo): void {
        $att = [];
        for ($i = 0; $i < $photos; $i++) {
            $att[] = $photo($id * 100 + $i);
        }
        $wall[$id] = ['id' => $id, 'date' => $clock, 'text' => $text, 'attachments' => $att];
    };
    Ctx::$quiet = true;

    $add(10, 'старый пост', 2);
    $add(11, 'старый пост 2', 0);
    $clock += 60;
    run_cron();
    $check($calls === [], 'первый запуск: старые посты не публикуются');

    $clock += 300;
    $add(12, $para(900), 2);       // 2 картинки + текст ~5400 знаков
    $add(13, 'Только текст', 0);
    $add(14, 'Одна картинка', 1);
    $add(15, '', 13);              // 13 картинок без текста
    run_cron();
    $expect = [
        'tg:sendMediaGroup:2', 'tg:sendMessage', 'tg:sendMessage', 'max:images:2', 'max:text', 'max:text',
        'tg:sendMessage', 'max:text',
        'tg:sendPhoto:1', 'tg:sendMessage', 'max:images:1', 'max:text',
        'tg:sendMediaGroup:10', 'tg:sendMediaGroup:3', 'max:images:12', 'max:images:1',
    ];
    $check($calls === $expect, 'новые посты: альбом/фото отдельно, текст следом, деление по лимитам');
    if ($calls !== $expect) {
        echo '       получено: ' . implode(', ', $calls) . PHP_EOL;
    }

    $calls = [];
    $clock += 300;
    run_cron();
    $check($calls === [], 'повторный запуск: ничего не дублируется');

    // MAX недоступен — Telegram всё равно получает пост; MAX — позже.
    $calls = [];
    $failMax = true;
    $clock += 300;
    $add(16, 'Пост при недоступном MAX', 0);
    run_cron();
    $check($calls === ['tg:sendMessage'], 'MAX недоступен: Telegram получил пост');
    $failMax = false;
    $clock += 60;
    run_cron();
    $check($calls === ['tg:sendMessage'], 'повтор не раньше назначенного времени');
    $clock += 6 * 60;
    run_cron();
    $check($calls === ['tg:sendMessage', 'max:text'], 'MAX получил пост при повторе, Telegram не задвоился');

    // Сбой на второй части текста: при повторе уходит только она.
    $calls = [];
    $clock += 300;
    $add(17, $para(900), 0);
    $failTgText = 0;
    Ctx::$fakeHttp = (static function (callable $inner) use (&$failTgText, &$calls) {
        $sent = 0;
        return static function (string $m, string $u, array $o) use ($inner, &$failTgText, &$sent) {
            if (str_ends_with($u, '/sendMessage') && ++$sent === 2 && $failTgText === 0) {
                $failTgText = -1;
                return ['code' => 502, 'body' => json_encode(['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway']), 'error' => ''];
            }
            return $inner($m, $u, $o);
        };
    })(Ctx::$fakeHttp);
    run_cron();
    $clock += 6 * 60;
    run_cron();
    $tgTexts = count(array_filter($calls, static fn($c) => $c === 'tg:sendMessage'));
    $check($tgTexts === 2, 'сбой на части 2: при повторе отправлена только часть 2 (всего 2 части)');

    // Журнал без текстов постов и без ключей.
    $log = (string)file_get_contents(Ctx::$data . '/journal.log');
    $check(!str_contains($log, 'Слово') && !str_contains($log, 'Только текст'), 'в журнале нет текстов постов');
    $check(!str_contains($log, 'SECRET'), 'в журнале нет ключей');

    // Смена режима на main: старое не копируется.
    $calls = [];
    file_put_contents($dir . '/config.ini', "MODE = main\nVK_SERVICE_KEY = vkSECRET\nVK_GROUP = 555\nTG_BOT_TOKEN = 1:TGSECRET\nTG_MAIN_CHAT = -1002\nMAX_BOT_TOKEN = MAXSECRET\nMAX_MAIN_CHAT = -78\n");
    $clock += 300;
    run_cron();
    $check($calls === [], 'переход на настоящие каналы: старые посты не публикуются');
    $clock += 300;
    $add(18, 'Первый пост в настоящих каналах', 0);
    run_cron();
    $check($calls === ['tg:sendMessage', 'max:text'], 'после перехода новый пост уходит один раз');

    // Уборка.
    foreach (glob($dir . '/data/tmp/*') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($dir . '/data/*') ?: [] as $f) {
        is_dir($f) ? @rmdir($f) : @unlink($f);
    }
    @rmdir($dir . '/data');
    @unlink($dir . '/config.ini');
    @rmdir($dir);

    echo $fails === 0 ? 'ИТОГ: все проверки пройдены' . PHP_EOL : 'ИТОГ: сбоев ' . $fails . PHP_EOL;
    return $fails === 0 ? 0 : 1;
}

// ---------------------------------------------------------------- Точка входа

function main(array $argv): int
{
    $args = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
            $args[$m[1]] = $m[2] ?? true;
        }
    }
    try {
        if (isset($args['selftest'])) {
            return run_selftest();
        }
        if (isset($args['check'])) {
            return run_check();
        }
        if (isset($args['dry-run'])) {
            return run_dry(isset($args['post']) ? (int)$args['post'] : null);
        }
        if (isset($args['test-post'])) {
            return run_test_post((int)$args['test-post']);
        }
        if (isset($args['status'])) {
            return run_status();
        }
        if (isset($args['probe'])) {
            return run_probe();
        }
        if (isset($args['audit'])) {
            return run_audit();
        }
        if (isset($args['enqueue'])) {
            return run_enqueue((string)$args['enqueue'], (string)($args['platforms'] ?? ''));
        }
        if (isset($args['retry'])) {
            return run_retry((int)$args['retry']);
        }
        if (isset($args['cron'])) {
            return run_cron();
        }
        say('Команды: --check, --dry-run [--post=N], --test-post=N, --status, --probe, --audit, --enqueue=ДАТА|N,N [--platforms=tg,max], --retry=N, --selftest, --cron');
        return 0;
    } catch (Throwable $e) {
        say('Ошибка: ' . redact($e->getMessage()));
        return 1;
    }
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    exit(main($argv));
}
