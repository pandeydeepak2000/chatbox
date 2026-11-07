import { Inter } from 'next/font/google';
import './globals.css';

// Self-hosted at build time (no request to Google from the browser).
const inter = Inter({ subsets: ['latin'], display: 'swap', variable: '--font-inter' });

export const metadata = {
  title: 'ChatBox · Support Workspace',
  description: 'Live chat, support tickets, leads and an AI knowledge base for your websites.',
};

export default function RootLayout({ children }) {
  return (
    <html lang="en" className={inter.variable}>
      <body>{children}</body>
    </html>
  );
}
