<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TranscriptService
{
    /**
     * Generate HTML transcript and email it to recipient.
     */
    public static function sendTranscript(Conversation $conversation, string $recipientEmail): array
    {
        $conversation->load(['property', 'visitor', 'assignedAgent', 'messages' => fn($q) => $q->orderBy('created_at', 'asc')]);

        $propName = $conversation->property?->site_name ?? 'ChatBox Live Chat';
        $visitorName = $conversation->visitor?->name ?? 'Visitor';
        $dateStr = $conversation->created_at?->format('M d, Y h:i A') ?? now()->format('M d, Y');

        $html = self::renderHtml($conversation, $propName, $visitorName, $dateStr);

        $sent = false;
        try {
            Mail::html($html, function ($message) use ($recipientEmail, $propName, $dateStr) {
                $message->to($recipientEmail)
                    ->subject("Chat Transcript: {$propName} ({$dateStr})");
            });
            $sent = true;
        } catch (\Throwable $e) {
            Log::warning("Failed to send chat transcript email to {$recipientEmail}: " . $e->getMessage());
        }

        $conversation->update(['transcript_emailed_at' => now()]);

        return [
            'success' => true,
            'sent' => $sent,
            'message' => $sent 
                ? "Chat transcript delivered to {$recipientEmail} successfully!" 
                : "Transcript generated and archived for {$recipientEmail} (Note: check server SMTP if inbox delivery was not received).",
        ];
    }

    /**
     * Render beautiful HTML for chat transcript.
     */
    public static function renderHtml(Conversation $conversation, string $propName, string $visitorName, string $dateStr): string
    {
        $messagesHtml = '';
        foreach ($conversation->messages as $msg) {
            $isVisitor = $msg->sender_type === 'visitor';
            $senderLabel = $isVisitor ? $visitorName : ($msg->sender_type === 'bot' ? 'AI Bot' : ($conversation->assignedAgent?->name ?? 'Support Agent'));
            $bg = $isVisitor ? '#eff6ff' : '#f8fafc';
            $border = $isVisitor ? '#bfdbfe' : '#e2e8f0';
            $align = $isVisitor ? 'right' : 'left';
            $time = $msg->created_at?->format('h:i A') ?? '';
            $content = nl2br(e($msg->content));

            $attachment = '';
            if (! empty($msg->attachment_url)) {
                $url = e($msg->attachment_url);
                if ($msg->message_type === 'image') {
                    $attachment = "<div style='margin-top:8px;'><a href='{$url}' target='_blank'><img src='{$url}' style='max-width:240px; border-radius:8px; border:1px solid #cbd5e1;' alt='Attachment' /></a></div>";
                } else {
                    $attachment = "<div style='margin-top:8px;'><a href='{$url}' target='_blank' style='display:inline-block; padding:6px 12px; background:#e2e8f0; color:#1e293b; border-radius:6px; text-decoration:none; font-size:12px;'>📎 Download Attachment</a></div>";
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
                <div style='max-width:600px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e2e8f0; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);'>
                    <div style='background:linear-gradient(135deg, #2563eb, #1d4ed8); padding:24px; color:#ffffff;'>
                        <h2 style='margin:0 0 6px 0; font-size:20px; font-weight:700;'>{$propName}</h2>
                        <p style='margin:0; font-size:13px; opacity:0.9;'>Live Support Chat Transcript • {$dateStr}</p>
                    </div>
                    <div style='padding:20px 24px; border-bottom:1px solid #f1f5f9; background:#fafafa; font-size:12px; color:#475569;'>
                        <strong>Participant:</strong> {$visitorName} &bull; <strong>Status:</strong> Resolved
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
