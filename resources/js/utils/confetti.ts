export interface ConfettiPoint {
    /** Horizontal position as a fraction of the viewport width (0–1). */
    x: number;
    /** Vertical position as a fraction of the viewport height (0–1). */
    y: number;
}

export interface ConfettiOptions {
    /** Where the burst starts: a viewport point, or the center of an element. */
    origin?: ConfettiPoint | HTMLElement;
    particleCount?: number;
    /** Angle of the burst in degrees, centered on straight up. 360 is a full ring. */
    spread?: number;
    /** Initial speed in CSS pixels per frame. */
    startVelocity?: number;
    /** CSS colors. Defaults to the brand tokens of the current theme. */
    colors?: string[];
    /** How long the burst lives, in milliseconds. */
    duration?: number;
    zIndex?: number;
}

interface Particle {
    x: number;
    y: number;
    velocityX: number;
    velocityY: number;
    size: number;
    color: string;
    rotation: number;
    rotationSpeed: number;
    wobble: number;
    wobbleSpeed: number;
    round: boolean;
}

interface Burst {
    particles: Particle[];
    startedAt: number;
    duration: number;
    zIndex: number;
}

const FRAME_MS = 1000 / 60;
const GRAVITY = 0.22;
const DRAG = 0.94;
const FADE_FROM = 0.6;

const BRAND_TOKENS = [
    '--primary',
    '--primary-strong',
    '--chart-5',
    '--info',
    '--warning',
    '--critical-hover',
];

const FALLBACK_COLORS = [
    '#ddd6fe',
    '#6d28d9',
    '#a78bfa',
    '#2563eb',
    '#d97706',
    '#ff8575',
];

const bursts = new Set<Burst>();

let canvas: HTMLCanvasElement | null = null;
let context: CanvasRenderingContext2D | null = null;
let frame: number | null = null;
let lastFrameAt = 0;

const prefersReducedMotion = (): boolean =>
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const themeColors = (): string[] => {
    const styles = getComputedStyle(document.documentElement);
    const colors = BRAND_TOKENS.map((token) =>
        styles.getPropertyValue(token).trim(),
    ).filter((color) => color !== '');

    return colors.length > 0 ? colors : FALLBACK_COLORS;
};

const resolveOrigin = (
    origin: ConfettiPoint | HTMLElement | undefined,
): { x: number; y: number } => {
    if (origin instanceof HTMLElement) {
        const rect = origin.getBoundingClientRect();

        return { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
    }

    const point = origin ?? { x: 0.5, y: 0.5 };

    return { x: point.x * window.innerWidth, y: point.y * window.innerHeight };
};

const random = (min: number, max: number): number =>
    min + Math.random() * (max - min);

const resizeCanvas = (): void => {
    if (!canvas || !context) {
        return;
    }

    const ratio = window.devicePixelRatio || 1;

    canvas.width = Math.round(window.innerWidth * ratio);
    canvas.height = Math.round(window.innerHeight * ratio);
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
};

const applyZIndex = (): void => {
    if (!canvas) {
        return;
    }

    const zIndex = Math.max(...[...bursts].map((burst) => burst.zIndex));

    canvas.style.zIndex = String(zIndex);
};

const ensureCanvas = (): void => {
    if (canvas) {
        return;
    }

    canvas = document.createElement('canvas');
    canvas.dataset.testid = 'confetti-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    Object.assign(canvas.style, {
        position: 'fixed',
        inset: '0',
        width: '100vw',
        height: '100vh',
        pointerEvents: 'none',
    });
    context = canvas.getContext('2d');
    document.body.appendChild(canvas);
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);
};

const teardown = (): void => {
    if (frame !== null) {
        cancelAnimationFrame(frame);
        frame = null;
    }

    window.removeEventListener('resize', resizeCanvas);
    canvas?.remove();
    canvas = null;
    context = null;
};

const drawParticle = (particle: Particle, opacity: number): void => {
    if (!context) {
        return;
    }

    context.save();
    context.globalAlpha = opacity;
    context.fillStyle = particle.color;
    context.translate(particle.x, particle.y);
    context.rotate(particle.rotation);
    context.scale(1, Math.cos(particle.wobble));

    if (particle.round) {
        context.beginPath();
        context.arc(0, 0, particle.size / 2, 0, Math.PI * 2);
        context.fill();
    } else {
        context.fillRect(
            -particle.size / 2,
            -particle.size / 4,
            particle.size,
            particle.size / 2,
        );
    }

    context.restore();
};

const tick = (now: number): void => {
    if (!canvas || !context) {
        return;
    }

    const step = Math.min((now - lastFrameAt) / FRAME_MS, 3);

    lastFrameAt = now;
    context.clearRect(0, 0, window.innerWidth, window.innerHeight);

    for (const burst of [...bursts]) {
        const progress = (now - burst.startedAt) / burst.duration;

        if (progress >= 1) {
            bursts.delete(burst);
            continue;
        }

        const opacity =
            progress < FADE_FROM ? 1 : 1 - (progress - FADE_FROM) / (1 - FADE_FROM);
        const drag = Math.pow(DRAG, step);

        for (const particle of burst.particles) {
            particle.velocityX *= drag;
            particle.velocityY = particle.velocityY * drag + GRAVITY * step;
            particle.x += particle.velocityX * step;
            particle.y += particle.velocityY * step;
            particle.rotation += particle.rotationSpeed * step;
            particle.wobble += particle.wobbleSpeed * step;
            drawParticle(particle, opacity);
        }
    }

    if (bursts.size === 0) {
        teardown();
        return;
    }

    frame = requestAnimationFrame(tick);
};

const createParticles = (
    origin: { x: number; y: number },
    count: number,
    spread: number,
    velocity: number,
    colors: string[],
): Particle[] =>
    Array.from({ length: count }, () => {
        const angle =
            ((-90 + random(-spread / 2, spread / 2)) * Math.PI) / 180;
        const speed = velocity * random(0.45, 1);

        return {
            x: origin.x,
            y: origin.y,
            velocityX: Math.cos(angle) * speed,
            velocityY: Math.sin(angle) * speed,
            size: random(5, 9),
            color: colors[Math.floor(Math.random() * colors.length)],
            rotation: random(0, Math.PI * 2),
            rotationSpeed: random(-0.2, 0.2),
            wobble: random(0, Math.PI * 2),
            wobbleSpeed: random(0.05, 0.15),
            round: Math.random() < 0.3,
        };
    });

/**
 * Fires a confetti burst on a shared, full-viewport canvas that is created on
 * demand and removed once the last burst ends. Returns a function that stops
 * this burst early. Does nothing on the server or when the user prefers
 * reduced motion.
 */
export const fireConfetti = (options: ConfettiOptions = {}): (() => void) => {
    if (typeof window === 'undefined' || prefersReducedMotion()) {
        return () => {};
    }

    const burst: Burst = {
        particles: createParticles(
            resolveOrigin(options.origin),
            options.particleCount ?? 40,
            options.spread ?? 360,
            options.startVelocity ?? 9,
            options.colors?.length ? options.colors : themeColors(),
        ),
        startedAt: performance.now(),
        duration: options.duration ?? 1800,
        zIndex: options.zIndex ?? 100,
    };

    bursts.add(burst);
    ensureCanvas();
    applyZIndex();

    if (frame === null) {
        lastFrameAt = performance.now();
        frame = requestAnimationFrame(tick);
    }

    return () => {
        if (!bursts.delete(burst)) {
            return;
        }

        if (bursts.size === 0) {
            teardown();
        } else {
            applyZIndex();
        }
    };
};
