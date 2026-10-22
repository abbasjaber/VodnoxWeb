<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#101D35">
    <link rel="icon" type="image/png" href="{{ asset('assets/vodnox/vodnox-app-icon-gold.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('assets/vodnox/vodnox-app-icon-gold.png') }}">
    <meta name="description" content="{{ __('vodnox.meta_description') }}">
    <title>VODNOX | {{ __('vodnox.sign_in') }}</title>
    <link rel="stylesheet" href="{{ $assetv('assets/vodnox/fonts.css') }}">
    <link rel="stylesheet" href="{{ $assetv('assets/vodnox/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ $assetv('assets/vodnox/theme.css') }}">
    <style>
        :root{--navy:#101d35;--gold:#f7b955;--cloud:#edf2f8;--muted:#5c6a7d}*{box-sizing:border-box}body{margin:0;background:#f9fafc;color:var(--navy);font-family:Alexandria,Arial,sans-serif;-webkit-font-smoothing:antialiased}button,input{font:inherit}button{cursor:pointer}button:focus-visible{outline:3px solid #d79127;outline-offset:5px}.page{display:grid;grid-template-columns:53% 47%;min-height:100svh;direction:ltr}.visual{position:relative;overflow:hidden;background:var(--navy);color:white;display:flex;flex-direction:column;justify-content:space-between;padding:52px 64px 38px;isolation:isolate;direction:rtl}.visual:before{content:"";position:absolute;inset:0;background:radial-gradient(ellipse at 45% 42%,#253b59 0,transparent 56%),linear-gradient(155deg,transparent 45%,#0c172b 90%);z-index:-2}.visual:after{content:"";position:absolute;inset:0;background-image:radial-gradient(#e9f0fa20 .7px,transparent .7px);background-size:27px 27px;mask-image:linear-gradient(black,transparent);z-index:-1}.visual-top{display:flex;align-items:center;justify-content:space-between;direction:ltr;font-size:10px;letter-spacing:2.7px;color:#acb7c8}.visual-top .mark{display:flex;align-items:center;gap:9px}.mark i{width:7px;height:7px;border-radius:50%;background:var(--gold);box-shadow:0 0 17px #f7b95565}.visual-top span:last-child{letter-spacing:1.5px;color:#62748e}.art{position:absolute;width:min(580px,84%);aspect-ratio:1;top:45%;left:50%;transform:translate(-50%,-50%);pointer-events:none}.art svg{width:100%;height:100%;overflow:visible}.orbit{transform-origin:300px 300px;animation:orbit 65s linear infinite}.reverse{animation-direction:reverse;animation-duration:85s}.pulse{animation:pulse 4s ease-in-out infinite}.visual-copy{position:relative;margin-top:360px;max-width:470px;z-index:2}.eyebrow{font-size:10px;letter-spacing:4px;color:var(--gold);direction:ltr;text-align:right;margin-bottom:19px}.visual-copy h2{font-size:clamp(32px,3.25vw,52px);font-weight:500;line-height:1.55;letter-spacing:-1.5px;margin:0 0 15px}.visual-copy h2 span{color:var(--gold)}.visual-copy p{font-size:12px;color:#aebcce;line-height:2.1;max-width:360px;margin:0}.visual-bottom{display:flex;justify-content:space-between;align-items:center;border-top:1px solid #ffffff14;padding-top:20px;margin-top:32px;font-size:9px;color:#778da9;direction:ltr;letter-spacing:1px}.signal{display:flex;gap:3px;align-items:center;height:14px}.signal b{width:2px;background:#f7b955aa;border-radius:3px;display:block}.form-panel{display:flex;flex-direction:column;padding:40px 48px 24px;direction:rtl;position:relative}.panel-top{display:flex;justify-content:space-between;align-items:center;gap:20px}.logo{display:block;width:185px;height:auto}.language{border:1px solid #dce3eb;border-radius:30px;background:transparent;height:36px;padding:0 14px;display:flex;align-items:center;gap:8px;color:#5b687b;font-size:11px;transition:.2s}.language:hover{background:var(--cloud);border-color:#a7b5c8}.language svg{width:15px;height:15px}.login-wrap{width:100%;max-width:368px;margin:auto;padding:70px 0 64px}.intro-tag{display:inline-flex;align-items:center;gap:8px;font-size:10px;color:#5c6a7d;margin-bottom:19px}.intro-tag:before{content:"";width:20px;height:2px;background:var(--gold)}h1{font-size:31px;line-height:1.6;font-weight:600;margin:0 0 10px;letter-spacing:-1px}.subtitle{color:var(--muted);font-size:12px;line-height:2;margin:0 0 37px}label{font-size:11px;font-weight:500;margin-bottom:11px;display:block}.field{position:relative;margin-bottom:23px}.input-icon{position:absolute;right:17px;top:18px;width:18px;height:18px;color:#5c6a7d;pointer-events:none}input{width:100%;height:56px;border:1px solid #dae1e9;background:white;border-radius:10px;padding:0 46px 0 17px;font-size:12px;color:var(--navy);outline:none;transition:.2s}input::placeholder{color:#6b7787;font-size:11px}input:hover{border-color:#aebac9}input:focus{border-color:#d99e40;box-shadow:0 0 0 4px #f7b95518}.password input{padding-left:49px}.eye{position:absolute;left:10px;top:9px;width:38px;height:38px;border:0;background:transparent;color:#5c6a7d;display:grid;place-items:center;border-radius:7px}.eye:hover{background:var(--cloud);color:var(--navy)}.eye svg{width:18px;height:18px}.submit{border:0;width:100%;height:56px;border-radius:10px;background:var(--navy);color:white;font-size:12px;font-weight:500;display:flex;justify-content:center;align-items:center;gap:14px;margin-top:32px;box-shadow:0 8px 20px #101d3517;transition:transform .2s,background .2s}.submit:hover{background:#20334f;transform:translateY(-2px)}.submit svg{width:18px;height:18px;color:var(--gold)}.secure{display:flex;justify-content:center;align-items:center;gap:6px;color:#5c6a7d;font-size:9px;margin-top:22px}.secure svg{width:12px;height:12px}.panel-bottom{font-size:9px;text-align:center;color:#5c6a7d;line-height:1.8;direction:ltr}.panel-bottom b{font-weight:500;color:#3f4d60}.toast{display:none;margin-top:20px;border:1px solid #e3c796;background:#fff7e9;padding:13px;border-radius:9px;font-size:11px;line-height:1.9;color:#755022}.toast.visible{display:block}.form-panel[dir=ltr]{direction:ltr}.form-panel[dir=ltr] .input-icon{right:auto;left:17px}.form-panel[dir=ltr] input{padding:0 17px 0 46px}.form-panel[dir=ltr] .password input{padding-right:49px}.form-panel[dir=ltr] .eye{left:auto;right:10px}.form-panel[dir=ltr] .submit svg{transform:rotate(180deg)}.visual[dir=ltr]{direction:ltr}.visual[dir=ltr] .eyebrow{text-align:left}.visual[dir=ltr] h2{letter-spacing:-2px;line-height:1.25}.visual[dir=ltr] .visual-copy p{line-height:1.9}@keyframes orbit{to{transform:rotate(360deg)}}@keyframes pulse{50%{opacity:.55}}@media(min-width:1600px){.visual{padding:60px 84px 45px}.visual-copy{max-width:600px}.login-wrap{max-width:405px}}@media(max-width:1100px){.visual{padding:40px 35px 30px}.form-panel{padding:35px 30px 22px}.visual-copy{margin-top:330px}.logo{width:160px}}@media(max-width:760px){.page{display:flex;flex-direction:column;min-height:100svh}.form-panel{min-height:100svh;padding:25px 25px 20px;order:0}.visual{display:none}.login-wrap{padding:55px 0 60px;max-width:380px}.logo{width:160px}h1{font-size:29px}.panel-bottom{font-size:8px}}@media(prefers-reduced-motion:reduce){*,*:before,*:after{animation:none!important;transition:none!important}}
        .alert{display:block;margin-top:20px;padding:13px;border-radius:9px;font-size:11px;line-height:1.9}.alert-danger{background:#fff0f0;border:1px solid #ffd4d4;color:#752222}
    </style>
</head>
<body>
<main class="page">
    <section class="visual" id="visual" aria-label="VODNOX" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
        <div class="visual-top"><div class="mark"><i></i> VODNOX / CONNECTED</div><span>01 — ACCESS</span></div>
        <div class="art" aria-hidden="true"><svg viewBox="0 0 600 600" fill="none"><defs><radialGradient id="glow"><stop stop-color="#F7B955" stop-opacity=".14"/><stop offset="1" stop-color="#F7B955" stop-opacity="0"/></radialGradient><linearGradient id="ring" x1="110" y1="140" x2="500" y2="430" gradientUnits="userSpaceOnUse"><stop stop-color="#F7B955"/><stop offset=".45" stop-color="#F7B955" stop-opacity=".1"/><stop offset="1" stop-color="#F7B955" stop-opacity=".8"/></linearGradient></defs><circle cx="300" cy="300" r="250" fill="url(#glow)"/><circle cx="300" cy="300" r="240" stroke="#A4B3C8" stroke-opacity=".11"/><circle cx="300" cy="300" r="198" stroke="#A4B3C8" stroke-opacity=".13" stroke-dasharray="2 11"/><g class="orbit"><circle cx="300" cy="300" r="240" stroke="url(#ring)" stroke-width="1.3" stroke-dasharray="170 1338"/><circle cx="300" cy="60" r="5" fill="#F7B955"/><circle cx="300" cy="60" r="13" stroke="#F7B955" stroke-opacity=".2"/></g><g class="orbit reverse"><ellipse cx="300" cy="300" rx="278" ry="122" transform="rotate(-33 300 300)" stroke="#778DA9" stroke-opacity=".23"/><circle cx="532" cy="147" r="4" fill="#A8B9D0"/></g><circle cx="300" cy="300" r="145" stroke="url(#ring)" stroke-width="2"/><circle cx="300" cy="300" r="133" fill="#13223A" fill-opacity=".7" stroke="#F7B955" stroke-opacity=".05"/><g id="wave" class="pulse"></g><circle cx="300" cy="300" r="175" stroke="#F7B955" stroke-opacity=".08"/><path d="M37 300H58M542 300H563M300 37V58M300 542V563" stroke="#7990AD" stroke-opacity=".3"/></svg></div>
        <div class="visual-copy"><div class="eyebrow">BUILT TO CONNECT</div><h2 id="hero">{!! app()->getLocale() === 'ar' ? 'كل اتصال.<br><span>فرصة جديدة.</span>' : 'Every connection.<br><span>A new possibility.</span>' !!}</h2><p id="heroText" style="white-space:pre-line">{{ app()->getLocale() === 'ar' ? 'مساحة واحدة تجمع اتصالاتك وفريقك.\nلتبقى أقرب، ويصل صوتك أبعد.' : 'One space for your conversations and your team.\nStay closer. Let your voice go further.' }}</p></div>
        <div class="visual-bottom"><span>PEOPLE. TECHNOLOGY. BEYOND BORDERS.</span><div class="signal" aria-hidden="true"><b style="height:4px"></b><b style="height:7px"></b><b style="height:12px"></b><b style="height:8px"></b><b style="height:5px"></b></div></div>
    </section>
    <section class="form-panel" id="panel" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
        <header class="panel-top">
            <img class="logo" src="{{ asset('assets/vodnox/vodnox-logo.png') }}" alt="VODNOX">
            <a href="{{ route('locale.switch', ['lang' => app()->getLocale() === 'ar' ? 'en' : 'ar']) }}" class="language" type="button" aria-label="Switch language">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/></svg>
                <span>{{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}</span>
            </a>
        </header>
        <div class="login-wrap">
            <div class="intro-tag">{{ app()->getLocale() === 'ar' ? 'بوابتك إلى VODNOX' : 'YOUR VODNOX WORKSPACE' }}</div>
            <h1>{{ __('vodnox.welcome_back') }}</h1>
            <p class="subtitle">{{ __('vodnox.login_subtitle') }}</p>

            @if ($errors->any())
                <div class="alert alert-danger">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}">
                @csrf
                <label for="username">{{ __('vodnox.username') }}</label>
                <div class="field">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="3.5"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/></svg>
                    <input id="username" name="username" type="text" value="{{ old('username') }}" placeholder="{{ app()->getLocale() === 'ar' ? 'أدخل اسم المستخدم' : 'Enter your username' }}" autocomplete="username" required autocapitalize="none" spellcheck="false">
                </div>
                <label for="password">{{ __('vodnox.password') }}</label>
                <div class="field password">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v3"/></svg>
                    <input id="password" name="password" type="password" placeholder="{{ app()->getLocale() === 'ar' ? 'أدخل كلمة المرور' : 'Enter your password' }}" autocomplete="current-password" required>
                    <button class="eye" type="button" id="eye" aria-label="Show password" aria-pressed="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/><path id="eyeSlash" d="M4 4l16 16" style="display:none"/></svg>
                    </button>
                </div>
                <button class="submit" type="submit">
                    <span>{{ __('vodnox.sign_in') }}</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M19 12H5m6-6-6 6 6 6"/></svg>
                </button>
            </form>
            <div class="secure">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/></svg>
                <span>{{ app()->getLocale() === 'ar' ? 'خصوصيتك في صميم كل اتصال' : 'Your privacy. At the heart of every connection.' }}</span>
            </div>
        </div>
        <footer class="panel-bottom">© 2026 <b>VODNOX</b> — {{ __('vodnox.rights_reserved') }}</footer>
    </section>
</main>
<script>
const ns='http://www.w3.org/2000/svg';for(let i=0;i<25;i++){const h=12+74*Math.pow(Math.cos((i-12)/12*Math.PI/2),2)*(0.45+0.55*Math.abs(Math.cos(i*1.8)));const r=document.createElementNS(ns,'rect');r.setAttribute('x',203+i*8);r.setAttribute('y',300-h/2);r.setAttribute('width',3);r.setAttribute('height',h);r.setAttribute('rx',1.5);r.setAttribute('fill','#F7B955');r.setAttribute('opacity',.35+.65*(1-Math.abs(i-12)/13));document.getElementById('wave').appendChild(r)}
document.getElementById('eye').addEventListener('click',()=>{const show=document.getElementById('password').type==='password';document.getElementById('password').type=show?'text':'password';document.getElementById('eye').setAttribute('aria-pressed',String(show));document.getElementById('eyeSlash').style.display=show?'block':'none'});
</script>
</body>
</html>
