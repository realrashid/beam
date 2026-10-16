import { defineConfig } from 'vitepress'

let year = new Date().getFullYear();

const title = 'Beam'
const description = 'Real-time calls, screen sharing and peer-to-peer file transfer for Laravel'
const url = 'https://realrashid.github.io/beam/'
const image = `https://api.placid.app/u/2wagk?hl=Beam&img=https://realrashid.github.io/beam&subline=Real-time%20calls%20for%20Laravel`
const twitter = 'rashidali05'
const github = 'https://github.com/realrashid/beam'
const linkedin = 'https://linkedin.com/in/realrashid'

export default defineConfig({
    lang: 'en-US',
    title,
    description,
    ignoreDeadLinks: true,
    base: '/beam/',
    lastUpdated: true,
    cleanUrls: true,
    appearance: 'dark',
    markdown: {
        defaultHighlightLang: 'php',
        theme: { dark: 'material-theme-palenight', light: 'github-light' },
    },

    head: [
        ['link', { rel: 'icon', type: 'image/svg+xml', href: '/beam/logo.svg' }],
        ['meta', { property: 'og:type', content: 'website' }],
        ['meta', { property: 'og:title', content: title }],
        ['meta', { property: 'og:image', content: image }],
        ['meta', { property: 'og:url', content: url }],
        ['meta', { property: 'og:description', content: description }],
        ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
        ['meta', { name: 'twitter:image', content: image }],
        ['meta', { name: 'twitter:site', content: `@${twitter}` }],
        ['meta', { name: 'twitter:title', content: title }],
        ['meta', { name: 'twitter:description', content: description }],
        ['meta', { name: 'theme-color', content: '#2dd4bf' }],
    ],

    themeConfig: {
        logo: { src: '/logo-mini.svg', width: 24, height: 24 },

        nav: [
            { text: 'Get Started', link: '/guide/introduction' },
            { text: 'Installation', link: '/guide/installation' },
            { text: 'Files', link: '/usage/files' },
            { text: 'Examples', link: '/example/support-desk' },
            { text: 'Demo App', link: '/demo/demo' },
        ],

        sidebar: [
            { text: 'Guide', collapsed: false, items: [
                { text: 'Introduction', link: '/guide/introduction' },
                { text: 'Installation', link: '/guide/installation' },
                { text: 'Configuration', link: '/guide/configuration' },
                { text: 'How It Works', link: '/guide/how-it-works' },
                { text: 'TURN Costs Money', link: '/guide/turn' },
                { text: 'Headless by Design', link: '/guide/headless' },
                { text: 'Browser Support', link: '/guide/browser-support' },
                { text: 'Laravel Boost', link: '/guide/boost' },
            ]},
            { text: 'Usage', collapsed: false, items: [
                { text: 'Usage', link: '/usage/usage' },
                { text: 'Calls', link: '/usage/calls' },
                { text: 'Rooms', link: '/usage/rooms' },
                { text: 'File Transfer', link: '/usage/files' },
                { text: 'Authorization', link: '/usage/authorization' },
                { text: 'Events', link: '/usage/events' },
                { text: 'Push Notifications', link: '/usage/push' },
                { text: 'Call Duration', link: '/usage/duration' },
                { text: 'Testing', link: '/usage/testing' },
                { text: 'Artisan Commands', link: '/usage/artisan' },
                { text: 'Errors', link: '/usage/errors' },
            ]},
            { text: 'Frontend', collapsed: false, items: [
                { text: 'Overview', link: '/frontend/overview' },
                { text: 'Blade', link: '/frontend/blade' },
                { text: 'jQuery', link: '/frontend/jquery' },
                { text: 'Livewire', link: '/frontend/livewire' },
                { text: 'Inertia + Vue', link: '/frontend/vue' },
                { text: 'Inertia + React', link: '/frontend/react' },
            ]},
            { text: 'TURN', collapsed: true, items: [
                { text: 'Overview', link: '/turn/overview' },
                { text: 'coturn', link: '/turn/coturn' },
                { text: 'Cloudflare', link: '/turn/cloudflare' },
                { text: 'Twilio', link: '/turn/twilio' },
                { text: 'Metered', link: '/turn/metered' },
            ]},
            { text: 'Demo', collapsed: true, items: [
                { text: 'Switchboard', link: '/demo/demo' },
                { text: 'Get Started', link: '/demo/get-started' },
            ]},
            { text: 'Examples — Calls', collapsed: false, items: [
                { text: 'Support Desk (Blade)', link: '/example/support-desk' },
                { text: 'Telehealth (Inertia + Vue)', link: '/example/telehealth' },
                { text: 'Marketplace (Inertia + React)', link: '/example/marketplace' },
                { text: 'Field Inspection (Livewire)', link: '/example/field-inspection' },
            ]},
            { text: 'Examples — Files', collapsed: false, items: [
                { text: 'File Handoff (jQuery)', link: '/example/file-handoff' },
                { text: 'Secure Documents (Blade)', link: '/example/secure-documents' },
            ]},
        ],

        socialLinks: [
            { icon: 'github', link: github },
            { icon: 'twitter', link: `https://twitter.com/${twitter}` },
            { icon: 'linkedin', link: linkedin },
        ],

        footer: {
            message: 'Made with ❤️ from Pakistan · Released under the MIT License.',
            copyright: `Copyright © ${year} Rashid Ali`,
        },

        search: { provider: 'local' },
        editLink: { pattern: `${github}/edit/main/docs/:path`, text: 'Edit this page on GitHub' },
    },
})
