<?php
session_start();

// Check if session is valid — supports PHP session_id AND persistent remember_token cookie
// (remember_token survives browser close/minimize on Android & iOS)
function isValidSession() {
    // 1. PHP session still alive — no file I/O needed
    if (isset($_SESSION['ghostlan_admin']) && $_SESSION['ghostlan_admin'] === true) {
        return true;
    }

    // 2. PHP session gone (mobile browser killed it) — check remember_token cookie
    if (!file_exists('session.json')) return false;

    $json_content = file_get_contents('session.json');
    $sessions = json_decode($json_content, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($sessions)) return false;

    $session_id     = session_id();
    $remember_token = isset($_COOKIE['remember_token']) ? $_COOKIE['remember_token'] : null;

    foreach ($sessions as $session) {
        $matched = false;

        // Prefer remember_token match (persists across browser restarts)
        if ($remember_token && isset($session['remember_token']) && $session['remember_token'] === $remember_token) {
            $matched = true;
        }
        // Fallback: PHP session_id match
        elseif (isset($session['session_id']) && $session['session_id'] === $session_id) {
            $matched = true;
        }

        if ($matched) {
            if (isset($session['expires_at']) && time() < $session['expires_at']) {
                // Restore PHP session so subsequent requests skip file I/O
                $_SESSION['ghostlan_admin'] = true;
                $_SESSION['username']       = $session['username']   ?? '';
                $_SESSION['name']           = $session['name']       ?? '';
                $_SESSION['is_admin']       = $session['is_admin']   ?? false;
                $_SESSION['login_time']     = $session['login_time'] ?? time();
                return true;
            }
            return false; // matched but expired
        }
    }
    return false;
}

if (!isValidSession()) {
    http_response_code(401);
    die("Unauthorized");
}

// Release session lock immediately — allows send.php, upload.php, load.php
// to run concurrently without queuing behind each other.
session_write_close();

// Paths
$chatlogFile = __DIR__ . '/chatlogs.json';
$uploadsDir = __DIR__ . '/uploads/';

// Get current user's name from session
$currentUser = isset($_SESSION['name']) ? $_SESSION['name'] : '';

function getInitials($name) {
    $words = explode(' ', trim($name));
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper($word[0]);
        }
    }
    return substr($initials, 0, 2);
}

function getCombinedChatAndUploads($chatlogFile, $uploadsDir, $currentUser) {
    $messages = [];

    // --- LOAD REACTIONS ---
    $reactions = [];
    $reactionsFile = __DIR__ . '/reactions.json';
    if (file_exists($reactionsFile)) {
        $rc = file_get_contents($reactionsFile);
        $rd = json_decode($rc, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($rd)) {
            $reactions = $rd;
        }
    }

    // --- LOAD CHAT LOGS ---
    if (file_exists($chatlogFile)) {
        $json_content = file_get_contents($chatlogFile);
        $chatLogs = json_decode($json_content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($chatLogs)) {
            foreach ($chatLogs as $msg) {
                if (!isset($msg['sender'], $msg['timestamp'])) continue;
                
                $timestamp = 0;
                if (!empty($msg['timestamp'])) {
                    $dt = DateTime::createFromFormat('Y-m-d H:i:s.u', $msg['timestamp']);
                    if ($dt !== false) {
                        $timestamp = (float)$dt->format('U.u');
                    } else {
                        $timestamp = (float)strtotime($msg['timestamp']);
                    }
                }
                
                $msgType = isset($msg['type']) ? $msg['type'] : 'text';
                $isSent = ($msg['sender'] === $currentUser);

                if ($msgType === 'upload') {
                    $fileName = isset($msg['message']) ? $msg['message'] : ($msg['file'] ?? '');
                    if ($fileName === '') continue;
                    $messages[] = [
                        'id'        => $msg['id'] ?? '',
                        'type'      => 'upload',
                        'file'      => htmlspecialchars($fileName),
                        'timestamp' => $timestamp,
                        'sender'    => htmlspecialchars($msg['sender']),
                        'isSent'    => $isSent
                    ];
                } else {
                    $content = isset($msg['message']) ? $msg['message'] : '';
                    $messages[] = [
                        'id'        => $msg['id'] ?? '',
                        'type'      => 'chat',
                        'sender'    => htmlspecialchars($msg['sender']),
                        'content'   => htmlspecialchars($content),
                        'timestamp' => $timestamp,
                        'isSent'    => $isSent
                    ];
                }
            }
        }
    }

    // --- SORT BY TIMESTAMP ---
    usort($messages, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

    $imageExts = ['jpg','jpeg','png','gif','webp','bmp','svg'];
    $audioExts = ['mp3','wav','ogg','flac','aac','m4a','opus'];
    $mimeMap   = [
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
        'flac' => 'audio/flac',
        'aac'  => 'audio/aac',
        'm4a'  => 'audio/mp4',
        'opus' => 'audio/ogg; codecs=opus'
    ];

    // --- RENDER ---
    $html = "";
    foreach ($messages as $msg) {
        $msgId       = htmlspecialchars($msg['id'] ?? '');
        $isSent      = $msg['isSent'];
        $sender      = $msg['sender'];
        $initials    = getInitials($sender);
        $timeDisplay = date('g:i A', (int)floor($msg['timestamp']));
        $msgClass    = $isSent ? 'sent' : 'received';
        $senderLabel = $isSent ? 'you' : strtolower($sender);

        // --- Build reaction badge HTML for this message ---
        $reactionHtml = '';
        if ($msgId && isset($reactions[$msgId])) {
            $reactionHtml = "<div class='msg-reactions'>";
            foreach (['😆', '❤️', '😡', '🥺'] as $em) {
                if (!empty($reactions[$msgId][$em])) {
                    $cnt = count($reactions[$msgId][$em]);
                    $iReacted = in_array($currentUser, $reactions[$msgId][$em]) ? ' reacted-by-me' : '';
                    $reactionHtml .= "<span class='reaction-badge{$iReacted}' data-emoji='" . htmlspecialchars($em) . "' data-msg-id='{$msgId}'>{$em}</span>";
                }
            }
            $reactionHtml .= "</div>";
        }

        $html .= "<div class='message-container {$msgClass}' data-msg-id='{$msgId}'>";
        $html .= "<div class='message-avatar'>{$initials}</div>";

        if ($msg['type'] === 'chat') {
            // ── Text — full bubble ──
            $html .= "<div class='message-bubble'>";
            $html .= "<div class='message-content'>{$msg['content']}</div>";
            $html .= "<div class='message-info'><span class='message-sender'>{$senderLabel}</span><span class='message-time'>{$timeDisplay}</span></div>";
            $html .= "</div>";

        } else {
            $file = $msg['file'];
            $url  = 'uploads/' . rawurlencode($file);
            $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if (in_array($ext, $imageExts)) {
                // ── Image — no bubble ──
                $html .= "<div class='message-media'>";
                $html .= "<a href='{$url}' target='_blank'><img src='{$url}' alt='" . htmlspecialchars($file) . "' style='max-width:240px;max-height:240px;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);' loading='lazy' /></a>";
                $html .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}</span><span class='message-time'>{$timeDisplay}</span></div>";
                $html .= "</div>";

            } elseif (in_array($ext, $audioExts)) {
                // ── Audio — inside bubble with player ──
                $mime = $mimeMap[$ext] ?? 'audio/' . $ext;
                $html .= "<div class='message-bubble'>";
                $html .= "<div class='message-content'>";
                $html .= "<div style='font-size:12px;margin-bottom:6px;font-weight:500;opacity:0.85;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'>{$file}</div>";
                $html .= "<audio controls preload='metadata' style='width:240px;max-width:100%;display:block;border-radius:6px;'><source src='{$url}' type='{$mime}'></audio>";
                $html .= "</div>";
                $html .= "<div class='message-info'><span class='message-sender'>{$senderLabel}</span><span class='message-time'>{$timeDisplay}</span></div>";
                $html .= "</div>";

            } else {
                // ── Document / other — keep bubble ──
                $linkColor = $isSent ? 'white' : '#1b74e4';
                $html .= "<div class='message-bubble'>";
                $html .= "<div class='message-content'><a href='{$url}' target='_blank' rel='noopener' style='display:flex;align-items:center;gap:8px;color:{$linkColor};text-decoration:none;'><span style='font-size:22px;'></span><span style='text-decoration:underline;font-weight:500;font-size:13px;word-break:break-all;'>{$file}</span></a></div>";
                $html .= "<div class='message-info'><span class='message-sender'>{$senderLabel}</span><span class='message-time'>{$timeDisplay}</span></div>";
                $html .= "</div>";
            }
        }

        $html .= $reactionHtml;
        $html .= "</div>"; // close .message-container
    }

    // If no messages, show empty state
    if (empty($messages)) {
        $html = "<div class='empty-chat'>
                    <div class='chat-icon'>
                        <svg viewBox='0 0 24 24'>
                            <path d='M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z'/>
                        </svg>
                    </div>
                    <h3>Welcome to Twitter (Clone) by Finnapple</h3>
                    <p>Twitter is a versatile, modern real-time chat application built with PHP and vanilla JavaScript that works ANYWHERE - whether you're on a local area network (LAN) or browsing from anywhere in the world! No complicated setup, no database required - just pure, instant communication.</p>
                </div>";
    }

    return $html;
}

echo getCombinedChatAndUploads($chatlogFile, $uploadsDir, $currentUser);
?>
