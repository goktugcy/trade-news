<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        text: string | null;
        // Increment to (re)play the word-by-word reveal — e.g. when a fresh
        // translation arrives, so it feels like the AI is typing it out.
        trigger?: number;
        speed?: number;
    }>(),
    { trigger: 0, speed: 32 },
);

const displayed = ref(props.text ?? '');
const typing = ref(false);
let timer: ReturnType<typeof setInterval> | undefined;

function stop(): void {
    if (timer) {
        clearInterval(timer);
        timer = undefined;
    }
    typing.value = false;
}

function play(): void {
    stop();

    const full = props.text ?? '';
    const tokens = full.match(/\S+\s*/g) ?? [];

    if (tokens.length === 0) {
        displayed.value = full;

        return;
    }

    typing.value = true;
    displayed.value = '';
    let i = 0;

    timer = setInterval(() => {
        displayed.value += tokens[i];
        i += 1;

        if (i >= tokens.length) {
            stop();
        }
    }, props.speed);
}

// Replay on explicit trigger; otherwise just mirror the text (e.g. live merges).
watch(() => props.trigger, () => play());
watch(
    () => props.text,
    (next) => {
        if (!typing.value) {
            displayed.value = next ?? '';
        }
    },
);

onBeforeUnmount(stop);
</script>

<template>
    <span>{{ displayed }}<span v-if="typing" class="ml-0.5 inline-block w-1.5 animate-pulse text-sky-500">▍</span></span>
</template>
