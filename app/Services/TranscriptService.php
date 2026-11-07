<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TranscriptService
{
    /**
     * Generate HTML transcript and email it to recipient.
     * Reports success only when the mailer accepted the message (audit H10).
     */
    public static function sendTranscript(Conversation $conversation, string $recipientEmail): array
    {
        $conversation->load(['property', 'visitor', 'assignedAgent', 'messages']);

        $propName = $conversation->property?->site_name ?? 'ChatBox Live Chat';
        $visitorName = $conversation->visitor?->name ?? 'Visitor';
        $dateStr = $conversation->created_at?->format('M d, Y h:i A') ?? now()->format('M d, Y');

        $html = self::renderHtml($conversation, $propName, $visitorName, $dateStr);
        // Subject is a header: strip CR/LF so a crafted site name cannot inject headers.
        $subject = str_replace(["\r", "\n"], ' ', "Chat Transcript: {$propName} ({$dateStr})");

        try {
            Mail::html($html, function ($message) use ($recipientEmail, $subject) {
                $message->to($recipientEmail)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning('Failed to send chat transcript email: ' . $e->getMessage(), ['conversation_id' => $conversation->id]);

            return [
                'success' => false,
                'sent' => false,
                'message' => 'We could not send the transcript right now. Please try again later.',
            ];
        }

        $conversation->update(['transcript_emailed_at' => now()]);

        return [
            'success' => true,
            'sent' => true,
            'message' => 'Chat transcript sent to ' . $recipientEmail . '.',
        ];
    }

    /**
     * Render HTML for chat transcript. Every dynamic value is escaped (audit M7).
     */
    public static function renderHtml(Conversation $conversation, string $propName, string $visitorName, string $dateStr): string
    {
        $propName = e($propName);
        $visitorName = e($visitorName);
        $dateStr = e($dateStr);

        $messagesHtml = '';
        foreach ($conversation->messages as $msg) {
            $isVisitor = $msg->sender_type === 'visitor';
            $senderLabel = e($isVisitor ? html_entity_decode($visitorName) : ($msg->sender_type === 'bot' ? 'AI Bot' : ($conversation->assignedAgent?->name ?? 'Support Agent')));
            $bg = $isVisitor ? '#eff6ff' : '#f8fafc';
            $border = $isVisitor ? '#bfdbfe' : '#e2e8f0';
            $align = $isVisitor ? 'right' : 'left';
            $time = e($msg->created_at?->format('h:i A') ?? '');
            $content = nl2br(e($msg->content));

            $attachment = '';
            if (! empty($msg->attachment_url)) {
                $rawUrl = $msg->attachment_url;
                if (str_starts_with($msg->getRawOriginal('attachment_url') ?? '', \App\Models\Message::ATTACHMENT_PREFIX)) {
                    // Links in e-mails live longer than in-app links.
                    $rawUrl = \App\Models\Attachment::signedUrlFor(substr($msg->getRawOriginal('attachment_url'), strlen(\App\Models\Message::ATTACHMENT_PREFIX)), 60 * 24 * 30);
                }
                $scheme = strtolower((string) parse_url($rawUrl, PHP_URL_SCHEME));
                if (in_array($scheme, ['http', 'https'], true)) {
                    $url = e($rawUrl);
                    $attachment = "<div style='margin-top:8px;'><a href='{$url}' target='_blank' rel='noopener' style='display:inline-block; padding:6px 12px; background:#e2e8f0; color:#1e293b; border-radius:6px; text-decoration:none; font-size:12px;'>📎 Download Attachment</a></div>";
                }
            }

            $messagesHtml .= "
                <div style='margin-bottom:14px; text-align:{$align};'>
                    <div style='font-size:11px; color:#64748b; margin-bottom:4px;'><strong>{$senderLabel}</strong> • {$time}</div>
                    <div style='display:inline-block; max-width:85%; background:{$bg}; border:1px solid {$border}; padding:10px 14px; border-radius:10px; text-align:left; font-size:13px; line-height:1.5; color:#0f172a;'>
                        {$content}
                        {$attachment}
                    </div>
                </div>
            ";
        }

        return "
            <!DOCTYPE html>
            <html>
            <head><meta charset='UTF-8'><title>Chat Transcript</title></head>
            <body style='font-family:-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif; background:#f1f5f9; padding:24px; margin:0;'>
                <div style='max-width:600px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e2e8f0;'>
                    <div style='background:#1d4ed8; padding:24px; color:#ffffff;'>
                        <h2 style='margin:0 0 6px 0; font-size:20px; font-weight:700;'>{$propName}</h2>
                        <p style='margin:0; font-size:13px; opacity:0.9;'>Live Support Chat Transcript • {$dateStr}</p>
                    </div>
                    <div style='padding:20px 24px; border-bottom:1px solid #f1f5f9; background:#fafafa; font-size:12px; color:#475569;'>
                        <strong>Participant:</strong> {$visitorName}
                    </div>
                    <div style='padding:24px;'>
                        {$messagesHtml}
                    </div>
                    <div style='background:#f8fafc; border-top:1px solid #e2e8f0; padding:16px 24px; text-align:center; font-size:11px; color:#94a3b8;'>
                        Generated automatically by {$propName} Live Chat.
                    </div>
                </div>
            </body>
            </html>
        ";
    }
}
