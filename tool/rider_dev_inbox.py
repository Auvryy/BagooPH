"""Loopback SMTP receiver and inbox for synthetic local account tests."""
import email
import email.policy
import html
import json
import socketserver
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

messages = []
lock = threading.Lock()

class Smtp(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b'220 Bagoo local test inbox\r\n')
        recipient = None
        while line := self.rfile.readline(1024 * 1024):
            text = line.decode('utf-8', 'replace').strip()
            command = text.upper()
            if command.startswith(('EHLO', 'HELO')):
                self.wfile.write(b'250-localhost\r\n250 SIZE 20000000\r\n')
            elif command.startswith('MAIL FROM:'):
                self.wfile.write(b'250 OK\r\n')
            elif command.startswith('RCPT TO:'):
                recipient = text.split(':', 1)[1].strip().strip('<>')
                if not recipient.lower().endswith(('@bagoo.test', '@example.test')):
                    self.wfile.write(b'550 Synthetic test recipients only\r\n')
                    recipient = None
                else:
                    self.wfile.write(b'250 OK\r\n')
            elif command == 'DATA' and recipient:
                self.wfile.write(b'354 End with a dot\r\n')
                body = bytearray()
                while part := self.rfile.readline(1024 * 1024):
                    if part == b'.\r\n': break
                    body.extend(part[1:] if part.startswith(b'..') else part)
                message = email.message_from_bytes(bytes(body), policy=email.policy.default)
                content = message.get_body(preferencelist=('plain', 'html'))
                with lock:
                    messages.append({'to': recipient, 'subject': str(message['subject']), 'body': content.get_content() if content else ''})
                self.wfile.write(b'250 Delivered to local inbox\r\n')
            elif command == 'QUIT':
                self.wfile.write(b'221 Bye\r\n'); return
            elif command in ('RSET', 'NOOP'):
                self.wfile.write(b'250 OK\r\n')
            else:
                self.wfile.write(b'502 Unsupported command\r\n')

class Inbox(BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def do_GET(self):
        with lock: items = list(reversed(messages))
        if self.path == '/messages':
            payload = json.dumps(items).encode()
            content_type = 'application/json'
        else:
            cards = ''.join('<article><h2>'+html.escape(item['subject'])+'</h2><p>'+html.escape(item['to'])+'</p><pre>'+html.escape(item['body'])+'</pre></article>' for item in items)
            payload = ('<!doctype html><title>Bagoo local test inbox</title><style>body{font-family:Plus Jakarta Sans,sans-serif;max-width:900px;margin:32px auto;background:#fffafb;color:#0f172a}article{background:white;padding:24px;margin:20px 0;border-radius:8px}pre{font-family:inherit;white-space:pre-wrap}</style><h1>Bagoo local test inbox</h1><p>Only synthetic local recipients. Refresh to see new verification messages.</p>'+cards).encode()
            content_type = 'text/html; charset=utf-8'
        self.send_response(200)
        self.send_header('Content-Type', content_type)
        self.send_header('Cache-Control', 'no-store')
        self.end_headers(); self.wfile.write(payload)

if __name__ == '__main__':
    smtp = socketserver.ThreadingTCPServer(('127.0.0.1', 1027), Smtp)
    threading.Thread(target=smtp.serve_forever, daemon=True).start()
    print('Local test inbox ready on loopback HTTP port 8028; SMTP port 1027.', flush=True)
    ThreadingHTTPServer(('127.0.0.1', 8028), Inbox).serve_forever()
