// Stops PostCSS's config search from walking up to the repository root's
// own postcss.config.mjs (the original TypeScript/Next.js app's Tailwind
// config, requiring @tailwindcss/postcss -- a package this Laravel
// project's package.json never installs). resources/css/app.css only
// imports Bootstrap's precompiled CSS, so this project needs no PostCSS
// plugins of its own; an empty local config is enough to stop the
// upward search.
const config = {
    plugins: {},
};

export default config;
