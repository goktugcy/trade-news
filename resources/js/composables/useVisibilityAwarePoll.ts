import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Runs `fn` on a fixed interval, pausing while the tab is hidden (saves
 * battery/CPU) and firing immediately on mount and whenever the tab becomes
 * visible again. The foundation for the platform's polling-based "live" feel.
 */
export function useVisibilityAwarePoll(fn: () => void | Promise<void>, intervalMs = 15000): { stop: () => void } {
    let timer: ReturnType<typeof setInterval> | undefined;

    function run(): void {
        void fn();
    }

    function start(): void {
        stopTimer();
        timer = setInterval(run, intervalMs);
    }

    function stopTimer(): void {
        if (timer) {
            clearInterval(timer);
            timer = undefined;
        }
    }

    function onVisibilityChange(): void {
        if (document.hidden) {
            stopTimer();
        } else {
            run();
            start();
        }
    }

    onMounted(() => {
        run();
        start();
        document.addEventListener('visibilitychange', onVisibilityChange);
    });

    onBeforeUnmount(() => {
        stopTimer();
        document.removeEventListener('visibilitychange', onVisibilityChange);
    });

    return { stop: stopTimer };
}
