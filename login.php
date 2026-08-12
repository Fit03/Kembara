<?php
// login.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // 1. Check for empty inputs
    if (empty($email) && empty($password)) {
        $error = "Sila masukkan e-mel dan kata laluan anda.";
    } elseif (empty($email)) {
        $error = "Sila masukkan alamat e-mel anda.";
    } elseif (empty($password)) {
        $error = "Sila masukkan kata laluan anda.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Format e-mel tidak sah.";
    } else {
        // 3. Query database for user by email
        $stmt = $pdo->prepare("SELECT user_id, fullname, email, password, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = "E-mel ini tidak berdaftar dalam sistem.";
        } elseif (!password_verify($password, $user['password'])) {
            $error = "Kata laluan yang dimasukkan adalah salah.";
        } else {
            $_SESSION['user_id']  = $user['user_id'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['role']     = $user['role'];

            header("Location: dashboard.php");
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kembara</title>
    
    <!-- Tailwind CSS & daisyUI CDN -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    
    <!-- React, ReactDOM, Three.js, and Babel CDNs -->
    <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

    <style>
        body { font-family: 'Open Sans', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-base-200 relative overflow-x-hidden flex flex-col justify-between">

    <!-- WebGL Fluid Background Container -->
    <div id="pixel-liquid-bg-root" class="fixed inset-0 z-0 pointer-events-none"></div>

    <div class="relative z-10 flex-grow flex flex-col justify-between">
        <!-- Hero banner header -->
        <div class="pt-12 pb-24 container mx-auto px-4 text-center">
            <h1 class="mt-4 mb-2 text-3xl font-bold text-base-content">Selamat Datang</h1>
            <p class="text-base-content/70 text-sm font-medium">Sistem Kembara</p>
        </div>

        <!-- Login Card Overlay -->
        <div class="container mx-auto px-4 mb-auto">
            <div class="card shrink-0 w-full max-w-sm mx-auto shadow-2xl bg-base-100/90 backdrop-blur-md rounded-2xl border border-white/20">
                <form class="card-body" action="login.php" method="POST">
                    <h2 class="card-title text-2xl font-bold justify-center mb-1">Log Masuk</h2>

                    <!-- Dynamic daisyUI Alert -->
                    <?php if (!empty($error)): ?>
                        <div role="alert" class="alert alert-error text-sm p-3 mb-4 shadow-sm flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Email Field -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Emel</span>
                        </label>
                        <input type="email" name="email" placeholder="emel@selangor.gov.my" class="input input-bordered rounded-lg bg-base-100/60" required />
                    </div>

                    <!-- Password Field Container -->
                    <div class="form-control mt-2">
                        <label class="label">
                            <span class="label-text">Kata Laluan</span>
                        </label>
                        <div class="relative w-full">
                            <input
                                type="password"
                                id="password-input"
                                name="password"
                                placeholder="Kata Laluan"
                                class="input input-bordered rounded-lg w-full pr-12 text-base bg-base-100/60"
                                required
                            />
                            <button
                                type="button"
                                onclick="togglePassword()"
                                class="absolute inset-y-0 right-0 pr-4 flex items-center text-base-content/60 hover:text-base-content focus:outline-none"
                                aria-label="Tunjuk kata laluan"
                            >
                                <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-6 h-6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <svg id="eye-slash-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-6 h-6 hidden">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                </svg>
                            </button>
                        </div>

                        <!-- Forgot Password Link -->
                        <label class="label justify-end">
                            <a href="#" class="label-text-alt link link-hover text-sm">Lupa Kata Laluan?</a>
                        </label>
                    </div>

                    <!-- Submit Button -->
                    <div class="form-control mt-4">
                        <button type="submit" class="btn btn-outline btn-neutral">Log Masuk</button>
                    </div>
                </form>
            </div>
        </div>

        <footer class="py-6 mt-8">
            <div class="container mx-auto px-4 text-center">
                <p class="text-sm text-base-content/60 font-medium">© <span id="year"></span> Kembara · Perbendaharaan Negeri Selangor</p>
            </div>
        </footer>
    </div>

    <!-- Toggle Password JS -->
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password-input');
            const eyeIcon = document.getElementById('eye-icon');
            const eyeSlashIcon = document.getElementById('eye-slash-icon');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.classList.add('hidden');
                eyeSlashIcon.classList.remove('hidden');
            } else {
                passwordInput.type = 'password';
                eyeIcon.classList.remove('hidden');
                eyeSlashIcon.classList.add('hidden');
            }
        }
        document.getElementById('year').textContent = new Date().getFullYear();
    </script>

    <!-- PixelLiquidBg Component Mount Script -->
    <script type="text/babel">
        const { useEffect, useRef } = React;

        const face_vert = `
        attribute vec3 position;
        uniform vec2 px;
        uniform vec2 boundarySpace;
        varying vec2 uv;
        precision highp float;
        void main(){
            vec3 pos = position;
            vec2 scale = 1.0 - boundarySpace * 2.0;
            pos.xy = pos.xy * scale;
            uv = vec2(0.5) + pos.xy * 0.5;
            gl_Position = vec4(pos, 1.0);
        }`;

        const line_vert = `
        attribute vec3 position;
        uniform vec2 px;
        precision highp float;
        varying vec2 uv;
        void main(){
            vec3 pos = position;
            uv = 0.5 + pos.xy * 0.5;
            vec2 n = sign(pos.xy);
            pos.xy = abs(pos.xy) - px * 1.0;
            pos.xy *= n;
            gl_Position = vec4(pos, 1.0);
        }`;

        const mouse_vert = `
        precision highp float;
        attribute vec3 position;
        attribute vec2 uv;
        uniform vec2 center;
        uniform vec2 scale;
        uniform vec2 px;
        varying vec2 vUv;
        void main(){
            vec2 pos = position.xy * scale * 2.0 * px + center;
            vUv = uv;
            gl_Position = vec4(pos, 0.0, 1.0);
        }`;

        const advection_frag = `
        precision highp float;
        uniform sampler2D velocity;
        uniform float dt;
        uniform bool isBFECC;
        uniform vec2 fboSize;
        uniform vec2 px;
        varying vec2 uv;
        void main(){
            vec2 ratio = max(fboSize.x, fboSize.y) / fboSize;
            if(isBFECC == false){
                vec2 vel = texture2D(velocity, uv).xy;
                vec2 uv2 = uv - vel * dt * ratio;
                vec2 newVel = texture2D(velocity, uv2).xy;
                gl_FragColor = vec4(newVel, 0.0, 0.0);
            } else {
                vec2 spot_new = uv;
                vec2 vel_old = texture2D(velocity, uv).xy;
                vec2 spot_old = spot_new - vel_old * dt * ratio;
                vec2 vel_new1 = texture2D(velocity, spot_old).xy;
                vec2 spot_new2 = spot_old + vel_new1 * dt * ratio;
                vec2 error = spot_new2 - spot_new;
                vec2 spot_new3 = spot_new - error / 2.0;
                vec2 vel_2 = texture2D(velocity, spot_new3).xy;
                vec2 spot_old2 = spot_new3 - vel_2 * dt * ratio;
                vec2 newVel2 = texture2D(velocity, spot_old2).xy;
                gl_FragColor = vec4(newVel2, 0.0, 0.0);
            }
        }`;

        const color_frag = `
        precision highp float;
        uniform sampler2D velocity;
        uniform sampler2D palette;
        uniform sampler2D uBayer;
        uniform vec4 bgColor;
        uniform float uTime;
        uniform vec2 uRes;
        uniform float uPixelSize;

        varying vec2 uv;

        float hash(vec2 p) {
            return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453);
        }

        float noise(vec2 p) {
            vec2 i = floor(p);
            vec2 f = fract(p);
            vec2 u = f * f * (3.0 - 2.0 * f);
            return mix(
                mix(hash(i), hash(i + vec2(1.0, 0.0)), u.x),
                mix(hash(i + vec2(0.0, 1.0)), hash(i + vec2(1.0, 1.0)), u.x),
                u.y
            );
        }

        void main(){
            vec2 pixGrid = uRes / uPixelSize;
            vec2 pixUV   = (floor(uv * pixGrid) + 0.5) / pixGrid;

            vec2 vel  = texture2D(velocity, pixUV).xy;
            float len = clamp(length(vel) * 2.2, 0.0, 1.0);

            vec2 bayerUV = (mod(floor(gl_FragCoord.xy), 4.0) + 0.5) / 4.0;
            float dither  = texture2D(uBayer, bayerUV).r - 0.5;

            float noiseVal = noise(uv * 6.0 + uTime * 0.15) * 0.06 - 0.03;

            float t = clamp(len + dither * 0.12 + noiseVal, 0.0, 1.0);

            vec3 fluidColor = texture2D(palette, vec2(t, 0.5)).rgb;
            vec3 col        = mix(bgColor.rgb, fluidColor, t);

            float grain = hash(gl_FragCoord.xy + vec2(uTime * 137.0, uTime * 91.0));
            col += (grain - 0.5) * 0.085;

            float alpha = mix(bgColor.a, 1.0, t);
            gl_FragColor = vec4(clamp(col, 0.0, 1.0), alpha);
        }`;

        const divergence_frag = `
        precision highp float;
        uniform sampler2D velocity;
        uniform float dt;
        uniform vec2 px;
        varying vec2 uv;
        void main(){
            float x0 = texture2D(velocity, uv - vec2(px.x, 0.0)).x;
            float x1 = texture2D(velocity, uv + vec2(px.x, 0.0)).x;
            float y0 = texture2D(velocity, uv - vec2(0.0, px.y)).y;
            float y1 = texture2D(velocity, uv + vec2(0.0, px.y)).y;
            float divergence = (x1 - x0 + y1 - y0) / 2.0;
            gl_FragColor = vec4(divergence / dt);
        }`;

        const externalForce_frag = `
        precision highp float;
        uniform vec2 force;
        uniform vec2 center;
        uniform vec2 scale;
        uniform vec2 px;
        varying vec2 vUv;
        void main(){
            vec2 circle = (vUv - 0.5) * 2.0;
            float d = 1.0 - min(length(circle), 1.0);
            d *= d;
            gl_FragColor = vec4(force * d, 0.0, 1.0);
        }`;

        const poisson_frag = `
        precision highp float;
        uniform sampler2D pressure;
        uniform sampler2D divergence;
        uniform vec2 px;
        varying vec2 uv;
        void main(){
            float p0 = texture2D(pressure, uv + vec2(px.x * 2.0, 0.0)).r;
            float p1 = texture2D(pressure, uv - vec2(px.x * 2.0, 0.0)).r;
            float p2 = texture2D(pressure, uv + vec2(0.0, px.y * 2.0)).r;
            float p3 = texture2D(pressure, uv - vec2(0.0, px.y * 2.0)).r;
            float div = texture2D(divergence, uv).r;
            float newP = (p0 + p1 + p2 + p3) / 4.0 - div;
            gl_FragColor = vec4(newP);
        }`;

        const pressure_frag = `
        precision highp float;
        uniform sampler2D pressure;
        uniform sampler2D velocity;
        uniform vec2 px;
        uniform float dt;
        varying vec2 uv;
        void main(){
            float p0 = texture2D(pressure, uv + vec2(px.x, 0.0)).r;
            float p1 = texture2D(pressure, uv - vec2(px.x, 0.0)).r;
            float p2 = texture2D(pressure, uv + vec2(0.0, px.y)).r;
            float p3 = texture2D(pressure, uv - vec2(0.0, px.y)).r;
            vec2 v      = texture2D(velocity, uv).xy;
            vec2 gradP  = vec2(p0 - p1, p2 - p3) * 0.5;
            v = v - gradP * dt;
            gl_FragColor = vec4(v, 0.0, 1.0);
        }`;

        const viscous_frag = `
        precision highp float;
        uniform sampler2D velocity;
        uniform sampler2D velocity_new;
        uniform float v;
        uniform vec2 px;
        uniform float dt;
        varying vec2 uv;
        void main(){
            vec2 old  = texture2D(velocity, uv).xy;
            vec2 new0 = texture2D(velocity_new, uv + vec2(px.x * 2.0, 0.0)).xy;
            vec2 new1 = texture2D(velocity_new, uv - vec2(px.x * 2.0, 0.0)).xy;
            vec2 new2 = texture2D(velocity_new, uv + vec2(0.0, px.y * 2.0)).xy;
            vec2 new3 = texture2D(velocity_new, uv - vec2(0.0, px.y * 2.0)).xy;
            vec2 newv = 4.0 * old + v * dt * (new0 + new1 + new2 + new3);
            newv /= 4.0 * (1.0 + v * dt);
            gl_FragColor = vec4(newv, 0.0, 0.0);
        }`;

        const DEFAULT_LIGHT_PALETTE = ["#ffffff", "#FD96E5", "#F36AC3", "#FE4396", "#ff85b3"];

        function writePaletteData(data, stops) {
            const arr = stops.length === 1 ? [stops[0], stops[0]] : stops;
            for (let i = 0; i < arr.length; i++) {
                const c = new THREE.Color(arr[i]);
                data[i * 4] = Math.round(c.r * 255);
                data[i * 4 + 1] = Math.round(c.g * 255);
                data[i * 4 + 2] = Math.round(c.b * 255);
                data[i * 4 + 3] = 255;
            }
        }

        function makePaletteTexture(stops) {
            const arr = stops.length === 1 ? [stops[0], stops[0]] : stops;
            const w = arr.length;
            const data = new Uint8Array(w * 4);
            writePaletteData(data, arr);
            const tex = new THREE.DataTexture(data, w, 1, THREE.RGBAFormat);
            tex.magFilter = THREE.LinearFilter;
            tex.minFilter = THREE.LinearFilter;
            tex.wrapS = THREE.ClampToEdgeWrapping;
            tex.wrapT = THREE.ClampToEdgeWrapping;
            tex.generateMipmaps = false;
            tex.needsUpdate = true;
            return tex;
        }

        function makeBayerTexture() {
            const raw = [
                0, 136, 34, 170, 204, 68, 238, 102, 51, 187, 17, 153, 255, 119, 221, 85,
            ];
            const data = new Uint8Array(16 * 4);
            for (let i = 0; i < 16; i++) {
                data[i * 4] = raw[i];
                data[i * 4 + 1] = raw[i];
                data[i * 4 + 2] = raw[i];
                data[i * 4 + 3] = 255;
            }
            const tex = new THREE.DataTexture(data, 4, 4, THREE.RGBAFormat);
            tex.magFilter = THREE.NearestFilter;
            tex.minFilter = THREE.NearestFilter;
            tex.wrapS = THREE.RepeatWrapping;
            tex.wrapT = THREE.RepeatWrapping;
            tex.generateMipmaps = false;
            tex.needsUpdate = true;
            return tex;
        }

        class CommonGL {
            width = 1;
            height = 1;
            pixelRatio = 1;
            renderer = null;
            clock = null;
            time = 0;
            delta = 0;
            container = null;

            init(container) {
                this.container = container;
                this.pixelRatio = 1;
                this.resize();
                this.renderer = new THREE.WebGLRenderer({ antialias: false, alpha: true });
                this.renderer.autoClear = false;
                this.renderer.setClearColor(0x000000, 0);
                this.renderer.setPixelRatio(this.pixelRatio);
                this.renderer.setSize(this.width, this.height, false);
                const el = this.renderer.domElement;
                el.style.width = "100%";
                el.style.height = "100%";
                el.style.display = "block";
                this.clock = new THREE.Clock();
                this.clock.start();
            }

            resize() {
                if (!this.container) return;
                const r = this.container.getBoundingClientRect();
                this.width = Math.max(1, Math.floor(r.width));
                this.height = Math.max(1, Math.floor(r.height));
                this.renderer?.setSize(this.width, this.height, false);
            }

            update() {
                if (!this.clock) return;
                this.delta = this.clock.getDelta();
                this.time += this.delta;
            }
        }

        class MouseGL {
            coords = new THREE.Vector2();
            coords_old = new THREE.Vector2();
            diff = new THREE.Vector2();
            mouseMoved = false;
            isInside = false;
            isAutoActive = false;
            autoIntensity = 2.0;
            timer = null;
            container = null;
            onInteract = null;

            constructor() {
                this._move = this._onMove.bind(this);
                this._leave = () => { this.isInside = false; };
                this._touch = this._onTouch.bind(this);
            }

            init(container) {
                this.container = container;
                window.addEventListener("mousemove", this._move);
                window.addEventListener("touchmove", this._touch, { passive: true });
                window.addEventListener("touchstart", this._touch, { passive: true });
                document.addEventListener("mouseleave", this._leave);
            }

            dispose() {
                window.removeEventListener("mousemove", this._move);
                window.removeEventListener("touchmove", this._touch);
                window.removeEventListener("touchstart", this._touch);
                document.removeEventListener("mouseleave", this._leave);
            }

            _onMove(e) {
                if (!this.container) return;
                const r = this.container.getBoundingClientRect();
                this.isInside = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
                if (!this.isInside) return;
                this.onInteract?.();
                this._set(e.clientX, e.clientY);
            }

            _onTouch(e) {
                if (e.touches.length !== 1) return;
                const t = e.touches[0];
                this.onInteract?.();
                this._set(t.clientX, t.clientY);
            }

            _set(cx, cy) {
                if (!this.container) return;
                if (this.timer) clearTimeout(this.timer);
                const r = this.container.getBoundingClientRect();
                const nx = (cx - r.left) / r.width;
                const ny = (cy - r.top) / r.height;
                this.coords.set(nx * 2 - 1, -(ny * 2 - 1));
                this.mouseMoved = true;
                this.timer = setTimeout(() => { this.mouseMoved = false; }, 100);
            }

            setNormalized(x, y) {
                this.coords.set(x, y);
                this.mouseMoved = true;
            }

            update() {
                this.diff.subVectors(this.coords, this.coords_old);
                this.coords_old.copy(this.coords);
                if (this.coords_old.x === 0 && this.coords_old.y === 0) this.diff.set(0, 0);
                if (this.isAutoActive) this.diff.multiplyScalar(this.autoIntensity);
            }
        }

        class ShaderPass {
            constructor(renderer, vertShader, fragShader, uniforms, output = null) {
                this.renderer = renderer;
                this.uniforms = uniforms;
                this.output = output;
                this.scene = new THREE.Scene();
                this.camera = new THREE.Camera();
                this.material = new THREE.RawShaderMaterial({
                    vertexShader: vertShader,
                    fragmentShader: fragShader,
                    uniforms,
                });
                this.geometry = new THREE.PlaneGeometry(2, 2);
                this.scene.add(new THREE.Mesh(this.geometry, this.material));
            }

            render(to = this.output) {
                const r = this.renderer();
                if (!r) return;
                r.setRenderTarget(to);
                r.render(this.scene, this.camera);
                r.setRenderTarget(null);
            }

            dispose() {
                this.material?.dispose();
                this.geometry?.dispose();
            }
        }

        class AutoDriver {
            current = new THREE.Vector2();
            target = new THREE.Vector2();
            lastTime = performance.now();
            _tmp = new THREE.Vector2();

            constructor(mouse, getLastInteraction, speed = 0.4, resumeDelay = 1200) {
                this._mouse = mouse;
                this._getLastInteraction = getLastInteraction;
                this.speed = speed;
                this.resumeDelay = resumeDelay;
                this.enabled = true;
                this._pickTarget();
            }

            _pickTarget() {
                this.target.set((Math.random() * 2 - 1) * 0.8, (Math.random() * 2 - 1) * 0.8);
            }

            update() {
                if (!this.enabled) return;
                const now = performance.now();
                const idleMs = now - this._getLastInteraction();
                if (idleMs < this.resumeDelay) {
                    this._mouse.isAutoActive = false;
                    return;
                }
                this._mouse.isAutoActive = true;
                const dt = Math.min((now - this.lastTime) / 1000, 0.05);
                this.lastTime = now;
                const dir = this._tmp.subVectors(this.target, this.current);
                const dist = dir.length();
                if (dist < 0.02) {
                    this._pickTarget();
                    return;
                }
                dir.normalize();
                this.current.addScaledVector(dir, Math.min(this.speed * dt, dist));
                this._mouse.setNormalized(this.current.x, this.current.y);
            }
        }

        class FluidSim {
            fbos = {};

            constructor(gl, mouse, opts = {}) {
                this.gl = gl;
                this.mouse = mouse;
                this.opts = {
                    resolution: 0.4,
                    mouse_force: 8,
                    cursor_size: 110,
                    dt: 0.008,
                    BFECC: false,
                    isBounce: false,
                    isViscous: false,
                    iterations_poisson: 8,
                    ...opts,
                };
                this.fboSize = new THREE.Vector2();
                this.cellScale = new THREE.Vector2();
                this.boundarySpace = new THREE.Vector2();
                this._calcSize();
                this._createFBOs();
                this._createPasses();
            }

            _r = () => this.gl.renderer;

            _calcSize() {
                const w = Math.max(1, Math.round(this.opts.resolution * this.gl.width));
                const h = Math.max(1, Math.round(this.opts.resolution * this.gl.height));
                this.cellScale.set(1 / w, 1 / h);
                this.fboSize.set(w, h);
            }

            _makeFBO() {
                return new THREE.WebGLRenderTarget(this.fboSize.x, this.fboSize.y, {
                    type: THREE.HalfFloatType,
                    depthBuffer: false,
                    stencilBuffer: false,
                    minFilter: THREE.LinearFilter,
                    magFilter: THREE.LinearFilter,
                    wrapS: THREE.ClampToEdgeWrapping,
                    wrapT: THREE.ClampToEdgeWrapping,
                });
            }

            _createFBOs() {
                for (const n of ["vel_0", "vel_1", "vel_v0", "vel_v1", "div", "p0", "p1"])
                    this.fbos[n] = this._makeFBO();
            }

            _createPasses() {
                const { fbos, cellScale, fboSize, opts, _r: r } = this;

                const advUniforms = {
                    boundarySpace: { value: cellScale },
                    px: { value: cellScale },
                    fboSize: { value: fboSize },
                    velocity: { value: fbos.vel_0.texture },
                    dt: { value: opts.dt },
                    isBFECC: { value: false },
                };
                const advPass = new ShaderPass(r, face_vert, advection_frag, advUniforms, fbos.vel_1);
                const bGeo = new THREE.BufferGeometry();
                bGeo.setAttribute("position", new THREE.BufferAttribute(new Float32Array([-1, -1, 0, -1, 1, 0, -1, 1, 0, 1, 1, 0, 1, 1, 0, 1, -1, 0, 1, -1, 0, -1, -1, 0]), 3));
                const bMat = new THREE.RawShaderMaterial({ vertexShader: line_vert, fragmentShader: advection_frag, uniforms: advUniforms });
                const bLine = new THREE.LineSegments(bGeo, bMat);
                advPass.scene.add(bLine);
                this.advection = { pass: advPass, line: bLine };

                const efScene = new THREE.Scene();
                const efCam = new THREE.Camera();
                const efMesh = new THREE.Mesh(
                    new THREE.PlaneGeometry(1, 1),
                    new THREE.RawShaderMaterial({
                        vertexShader: mouse_vert,
                        fragmentShader: externalForce_frag,
                        blending: THREE.AdditiveBlending,
                        depthWrite: false,
                        uniforms: {
                            px: { value: cellScale },
                            force: { value: new THREE.Vector2() },
                            center: { value: new THREE.Vector2() },
                            scale: { value: new THREE.Vector2(opts.cursor_size, opts.cursor_size) },
                        },
                    })
                );
                efScene.add(efMesh);
                this.externalForce = { scene: efScene, camera: efCam, mesh: efMesh };

                this.divergencePass = new ShaderPass(r, face_vert, divergence_frag, {
                    boundarySpace: { value: cellScale },
                    velocity: { value: fbos.vel_v0.texture },
                    px: { value: cellScale },
                    dt: { value: opts.dt },
                }, fbos.div);

                const poisPass = new ShaderPass(r, face_vert, poisson_frag, {
                    boundarySpace: { value: cellScale },
                    pressure: { value: fbos.p0.texture },
                    divergence: { value: fbos.div.texture },
                    px: { value: cellScale },
                }, fbos.p1);
                this.poissonPass = { pass: poisPass, output0: fbos.p0, output1: fbos.p1 };

                this.pressurePass = new ShaderPass(r, face_vert, pressure_frag, {
                    boundarySpace: { value: cellScale },
                    pressure: { value: fbos.p0.texture },
                    velocity: { value: fbos.vel_v0.texture },
                    px: { value: cellScale },
                    dt: { value: opts.dt },
                }, fbos.vel_0);
            }

            resize() {
                this._calcSize();
                for (const k in this.fbos) this.fbos[k].setSize(this.fboSize.x, this.fboSize.y);
            }

            update(time) {
                const { opts, mouse, fbos } = this;
                const r = this.gl.renderer;
                if (!r) return;

                this.boundarySpace.copy(opts.isBounce ? new THREE.Vector2() : this.cellScale);

                {
                    const u = this.advection.pass.uniforms;
                    u.dt.value = opts.dt;
                    u.isBFECC.value = opts.BFECC;
                    this.advection.line.visible = opts.isBounce;
                    this.advection.pass.render();
                }

                {
                    const mf = opts.mouse_force;
                    const cs = opts.cursor_size;
                    const cx = this.cellScale.x;
                    const cy = this.cellScale.y;
                    const clampedX = Math.min(Math.max(mouse.coords.x, -1 + cs * cx * 2 + cx * 2), 1 - cs * cx * 2 - cx * 2);
                    const clampedY = Math.min(Math.max(mouse.coords.y, -1 + cs * cy * 2 + cy * 2), 1 - cs * cy * 2 - cy * 2);
                    const u = this.externalForce.mesh.material.uniforms;
                    u.force.value.set((mouse.diff.x / 2) * mf, (mouse.diff.y / 2) * mf);
                    u.center.value.set(clampedX, clampedY);
                    u.scale.value.set(cs, cs);
                    r.setRenderTarget(fbos.vel_1);
                    r.render(this.externalForce.scene, this.externalForce.camera);
                    r.setRenderTarget(null);
                }

                let velFBO = fbos.vel_1;

                this.divergencePass.uniforms.velocity.value = velFBO.texture;
                this.divergencePass.render();

                {
                    const { pass, output0, output1 } = this.poissonPass;
                    let p_in = output0, p_out = output1;
                    for (let i = 0; i < opts.iterations_poisson; i++) {
                        if (i % 2 === 0) { p_in = output0; p_out = output1; }
                        else { p_in = output1; p_out = output0; }
                        pass.uniforms.pressure.value = p_in.texture;
                        pass.render(p_out);
                    }
                    this.pressurePass.uniforms.pressure.value = p_out.texture;
                    this.pressurePass.uniforms.velocity.value = velFBO.texture;
                }

                this.pressurePass.render();
            }

            dispose() {
                for (const k in this.fbos) this.fbos[k]?.dispose();
                this.advection.pass.dispose();
                this.divergencePass.dispose();
                this.pressurePass.dispose();
            }
        }

        function PixelLiquidBgApp() {
            const mountRef = useRef(null);

            useEffect(() => {
                const container = mountRef.current;
                if (!container) return;

                const gl = new CommonGL();
                gl.init(container);
                container.appendChild(gl.renderer.domElement);

                const mouse = new MouseGL();
                mouse.init(container);
                mouse.autoIntensity = 2.4;

                const palette = makePaletteTexture(DEFAULT_LIGHT_PALETTE);
                const bayerTex = makeBayerTexture();

                const sim = new FluidSim(gl, mouse, {
                    resolution: 0.4,
                    mouse_force: 8,
                    cursor_size: 110,
                    dt: 0.008,
                    BFECC: false,
                    isBounce: false,
                    isViscous: false,
                    iterations_poisson: 8,
                });

                const outputUniforms = {
                    velocity: { value: sim.fbos.vel_0.texture },
                    palette: { value: palette },
                    uBayer: { value: bayerTex },
                    bgColor: { value: new THREE.Vector4(0.96, 0.96, 0.98, 1.0) },
                    uTime: { value: 0 },
                    uRes: { value: new THREE.Vector2(gl.width, gl.height) },
                    uPixelSize: { value: 1 },
                    boundarySpace: { value: new THREE.Vector2() },
                    px: { value: new THREE.Vector2() },
                };

                const outputScene = new THREE.Scene();
                const outputCam = new THREE.Camera();
                const outputMesh = new THREE.Mesh(
                    new THREE.PlaneGeometry(2, 2),
                    new THREE.RawShaderMaterial({
                        vertexShader: face_vert,
                        fragmentShader: color_frag,
                        transparent: true,
                        depthWrite: false,
                        uniforms: outputUniforms,
                    })
                );
                outputScene.add(outputMesh);

                let lastInteraction = performance.now();
                mouse.onInteract = () => { lastInteraction = performance.now(); };
                const driver = new AutoDriver(mouse, () => lastInteraction, 0.45, 1200);

                const handleResize = () => {
                    gl.resize();
                    sim.resize();
                    outputUniforms.uRes.value.set(gl.width, gl.height);
                };
                const ro = new ResizeObserver(handleResize);
                ro.observe(container);

                let raf = 0;
                let running = true;

                const loop = () => {
                    if (!running) return;
                    raf = requestAnimationFrame(loop);
                    driver.update();
                    mouse.update();
                    gl.update();
                    outputUniforms.uTime.value = gl.time;
                    sim.update(gl.time);
                    const r = gl.renderer;
                    if (r) {
                        r.setRenderTarget(null);
                        r.render(outputScene, outputCam);
                    }
                };
                loop();

                const onVisibility = () => {
                    if (document.hidden) {
                        running = false;
                        cancelAnimationFrame(raf);
                    } else {
                        running = true;
                        loop();
                    }
                };
                document.addEventListener("visibilitychange", onVisibility);

                return () => {
                    running = false;
                    cancelAnimationFrame(raf);
                    ro.disconnect();
                    document.removeEventListener("visibilitychange", onVisibility);
                    mouse.dispose();
                    sim.dispose();
                    palette.dispose();
                    bayerTex.dispose();
                    outputMesh.material.dispose();
                    outputMesh.geometry.dispose();
                    const canvas = gl.renderer?.domElement;
                    gl.renderer?.dispose();
                    if (canvas?.parentNode) canvas.parentNode.removeChild(canvas);
                };
            }, []);

            return <div ref={mountRef} className="w-full h-full" />;
        }

        const root = ReactDOM.createRoot(document.getElementById('pixel-liquid-bg-root'));
        root.render(<PixelLiquidBgApp />);
    </script>
</body>
</html>