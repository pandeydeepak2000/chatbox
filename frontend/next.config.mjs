/**
 * ChatBox dashboard — static export for cPanel (no Node.js process in production).
 * `npm run build` writes ./out; copy it to ../public/app (see npm run deploy:local).
 * Laravel serves the same index.html for /app/* and for website admin URLs /{slug}/admin.
 */
const nextConfig = {
  output: 'export',
  basePath: '/app',
  trailingSlash: false,
  images: { unoptimized: true },
  reactStrictMode: true,
};

export default nextConfig;
