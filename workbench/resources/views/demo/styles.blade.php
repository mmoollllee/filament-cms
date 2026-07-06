{{--
    Shared demo stylesheet (standalone + onepager shells). Self-contained, no
    build step: styles every class the package's frontend block + <x-site.*>
    components emit, plus the layout-preset classes used by the seeder.
    Expects: $primary (resolved tenant color).
--}}
    <style>
        :root{
            --primary: {{ $primary }};
            --primary-dark: color-mix(in oklab, {{ $primary }} 80%, black);
            --primary-tint: color-mix(in oklab, {{ $primary }} 10%, white);
            --primary-tint-2: color-mix(in oklab, {{ $primary }} 18%, white);
            --ink:#0f172a; --muted:#64748b; --border:#e6e8ee; --bg:#ffffff; --bg-alt:#f8fafc;
            --code-bg:#0f172a; --code-ink:#e2e8f0; --radius:14px; --maxw:1080px;
        }
        *{box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--ink);background:var(--bg);line-height:1.65;-webkit-font-smoothing:antialiased}
        a{color:var(--primary-dark);text-decoration:none}
        a:hover{text-decoration:underline}
        .container{max-width:var(--maxw);margin-inline:auto;padding-inline:1.25rem}

        /* ---- top nav ---- */
        .site-nav{position:sticky;top:0;z-index:50;backdrop-filter:saturate(1.4) blur(8px);background:color-mix(in oklab,var(--bg) 86%,transparent);border-bottom:1px solid var(--border)}
        .site-nav .container{display:flex;align-items:center;justify-content:space-between;height:64px;gap:1rem}
        .brand{display:flex;align-items:center;gap:.6rem;font-weight:800;font-size:1.1rem;color:var(--ink)}
        .brand:hover{text-decoration:none}
        .brand .dot{width:26px;height:26px;border-radius:8px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));display:grid;place-items:center;color:#fff;font-size:.8rem}
        .nav-links{display:flex;align-items:center;gap:.35rem;flex-wrap:wrap}
        .nav-links a{padding:.45rem .7rem;border-radius:8px;color:var(--muted);font-weight:500;font-size:.95rem}
        .nav-links a:hover{background:var(--bg-alt);color:var(--ink);text-decoration:none}
        .nav-links a.active{color:var(--primary-dark);background:var(--primary-tint)}
        .nav-cta{background:var(--primary)!important;color:#fff!important}
        .nav-cta:hover{background:var(--primary-dark)!important}

        /* ---- hero ---- */
        .hero{background:radial-gradient(1200px 400px at 70% -10%,var(--primary-tint-2),transparent),var(--bg-alt);border-bottom:1px solid var(--border)}
        .hero .container{padding-block:clamp(2.5rem,6vw,5rem)}
        .hero--home .container{padding-block:clamp(3.5rem,8vw,6.5rem)}
        .eyebrow{margin:0 0 .6rem;text-transform:uppercase;letter-spacing:.12em;font-size:.78rem;font-weight:700;color:var(--primary-dark)}
        .hero h1{margin:0;font-size:clamp(1.9rem,4.5vw,3.2rem);line-height:1.08;letter-spacing:-.02em;font-weight:820;max-width:18ch}
        .hero .subtitle{margin:1rem 0 0;font-size:clamp(1.05rem,2vw,1.3rem);color:var(--muted);max-width:60ch}
        .hero .actions{margin-top:1.8rem;display:flex;gap:.7rem;flex-wrap:wrap}

        /* ---- body ---- */
        .page-body{padding-block:clamp(2rem,5vw,3.5rem)}
        .page-body>.container>.prose:first-child{margin-top:0}

        /* ---- prose / richtext (article typography) ---- */
        .prose,.richtext{max-width:none}
        .prose h1,.richtext h1{font-size:2rem;line-height:1.15;margin:2.2rem 0 .8rem;letter-spacing:-.01em}
        .prose h2,.richtext h2{font-size:1.5rem;line-height:1.2;margin:2rem 0 .7rem;letter-spacing:-.01em}
        .prose h3,.richtext h3{font-size:1.18rem;margin:1.6rem 0 .5rem}
        .prose p,.richtext p{margin:.7rem 0}
        .prose ul,.prose ol,.richtext ul,.richtext ol{margin:.7rem 0;padding-left:1.3rem}
        .prose li,.richtext li{margin:.3rem 0}
        .prose a,.richtext a{text-decoration:underline;text-underline-offset:3px}
        .prose strong{font-weight:700}
        .prose blockquote,.richtext blockquote{margin:1rem 0;padding:.4rem 1rem;border-left:3px solid var(--primary);background:var(--bg-alt);border-radius:6px;color:var(--muted)}
        .prose :not(pre)>code,.richtext :not(pre)>code{background:var(--primary-tint);color:var(--primary-dark);padding:.12em .4em;border-radius:6px;font-size:.88em;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
        .prose pre,.richtext pre{background:var(--code-bg);color:var(--code-ink);padding:1rem 1.15rem;border-radius:12px;overflow:auto;margin:1.1rem 0;font-size:.86rem;line-height:1.6;box-shadow:0 10px 30px -12px rgba(2,6,23,.45)}
        .prose pre code,.richtext pre code{background:none;color:inherit;padding:0;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
        .prose img,.richtext img,.prose video,.richtext video{max-width:100%;height:auto;border-radius:12px;display:block}
        .prose table,.richtext table{width:100%;border-collapse:collapse;margin:1rem 0;font-size:.92rem}
        .prose th,.prose td,.richtext th,.richtext td{border:1px solid var(--border);padding:.5rem .7rem;text-align:left}
        .prose th,.richtext th{background:var(--bg-alt);font-weight:600}

        /* ---- section block (grid container) ---- */
        .grid{display:grid}
        .stagger{gap:1.5rem}
        .gap-3{gap:.75rem}.gap-4{gap:1rem}.gap-6{gap:1.5rem}
        section[class*="grid"]{margin-block:clamp(1.5rem,4vw,2.75rem)}
        .relative{position:relative}.absolute{position:absolute}.inset-0{inset:0}
        .z-0{z-index:0}.z-10{z-index:10}.overflow-hidden{overflow:hidden}
        .h-full{height:100%}.w-full{width:100%}.object-cover{object-fit:cover}.pointer-events-none{pointer-events:none}
        .rounded-section{border-radius:20px}.rounded-panel{border-radius:14px}
        .anim{animation:fadeup .5s ease both}
        @keyframes fadeup{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
        .p-6{padding:1.5rem}@media(min-width:768px){.md\:p-7{padding:1.75rem}}

        /* ---- layout-preset classes used by the seeder ---- */
        @media(min-width:768px){
            .md\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}
            .md\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}
            .md\:col-span-2{grid-column:span 2/span 2}
        }
        .items-center{align-items:center}
        .text-center{text-align:center}.mx-auto{margin-inline:auto}
        .max-w-2xl{max-width:42rem}.max-w-3xl{max-width:48rem}
        @media(min-width:640px){.sm\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(min-width:1024px){.lg\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}}

        /* ---- card ---- */
        .card{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:1.4rem;box-shadow:0 1px 2px rgba(2,6,23,.04)}

        /* ---- hint (the demo's custom block, see /howto/custom-blocks) ---- */
        .hint{border-left:4px solid;border-radius:10px;padding:.9rem 1.1rem;background:var(--bg-alt)}
        .hint-title{font-weight:700;margin:0 0 .3rem}
        .hint-info{border-color:#0ea5e9;background:#f0f9ff}
        .hint-success{border-color:#22c55e;background:#f0fdf4}
        .hint-warning{border-color:#f59e0b;background:#fffbeb}

        .section-header h1,.section-header h2,.section-header h3{margin:.2rem 0}

        /* ---- buttons (rich-editor button-group) ---- */
        .btn{display:inline-flex;align-items:center;gap:.45rem;padding:.62rem 1.1rem;border-radius:10px;font-weight:600;font-size:.95rem;line-height:1;border:1.5px solid transparent;cursor:pointer;transition:.15s;text-decoration:none}
        .btn:hover{text-decoration:none;transform:translateY(-1px)}
        .btn svg{width:1.05em;height:1.05em}
        .btn-primary{background:var(--primary);color:#fff;box-shadow:0 8px 20px -8px var(--primary)}
        .btn-primary:hover{background:var(--primary-dark)}
        .btn-secondary{border-color:var(--primary);color:var(--primary-dark);background:transparent}
        .btn-secondary:hover{background:var(--primary-tint)}
        .btn-surface{background:#fff;color:var(--ink);border-color:var(--border);box-shadow:0 4px 14px -8px rgba(2,6,23,.3)}
        .btn-soft{background:var(--bg-alt);color:var(--ink)}
        .btn-dark{background:var(--ink);color:#fff}
        .btn-light{background:#fff;color:var(--ink);border-color:var(--border)}
        .btn-ghost-light{background:transparent;color:var(--ink)}
        .btn-sm{padding:.42rem .8rem;font-size:.85rem}
        .btn-lg{padding:.8rem 1.4rem;font-size:1.05rem}
        .btn-group{display:flex;gap:.7rem;flex-wrap:wrap;margin:1.1rem 0}
        .btn-group-center{justify-content:center}
        .btn-group-end{justify-content:flex-end}

        /* ---- navigation cards (rich-editor) ---- */
        .nav-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin:1.2rem 0}
        .nav-card{display:block;padding:1.1rem 1.2rem;border:1px solid var(--border);border-radius:12px;background:var(--bg);transition:.15s;text-decoration:none;color:inherit}
        .nav-card:hover{text-decoration:none;border-color:var(--primary);box-shadow:0 12px 30px -16px var(--primary);transform:translateY(-2px)}
        .nav-card__label{display:flex;align-items:center;justify-content:space-between;gap:.5rem;font-weight:700;color:var(--ink)}
        .nav-card__label svg{color:var(--primary)}
        .nav-card__text{display:block;margin-top:.3rem;color:var(--muted);font-size:.92rem}

        /* ---- listing cards ---- */
        .listing-card{display:block;padding:1.1rem 1.2rem;border:1px solid var(--border)!important;border-radius:12px!important;background:var(--bg);transition:.15s;text-decoration:none!important;color:inherit!important}
        .listing-card:hover{border-color:var(--primary)!important;box-shadow:0 12px 30px -16px var(--primary);transform:translateY(-2px)}
        .listing-card strong{color:var(--ink)}

        /* ---- footer ---- */
        .site-footer{margin-top:4rem;background:var(--ink);color:#cbd5e1}
        .site-footer .container{padding-block:2.5rem;display:grid;gap:1.2rem}
        .site-footer a{color:#cbd5e1}
        .footer-nav{display:flex;gap:1.2rem;flex-wrap:wrap}
        .footer-meta{display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;border-top:1px solid rgba(255,255,255,.1);padding-top:1.2rem;font-size:.88rem;color:#94a3b8}
        .footer-social{display:flex;gap:.8rem}

        /* ---- onepager demo (tenant B) ---- */
        .onepager-demo-section{padding-block:3rem;border-top:1px solid var(--border);scroll-margin-top:4.5rem}
        .onepager-demo-section:nth-child(odd){background:var(--bg-alt)}

        /* ---- breadcrumbs (nested pages) ---- */
        .demo-breadcrumbs{border-bottom:1px solid var(--border);background:var(--bg-alt);font-size:.85rem}
        .demo-breadcrumbs .container{display:flex;align-items:center;gap:.45rem;padding-block:.55rem;flex-wrap:wrap}
        .demo-breadcrumbs span[aria-hidden]{color:var(--muted)}
        .demo-breadcrumbs span[aria-current]{color:var(--muted)}
    </style>
