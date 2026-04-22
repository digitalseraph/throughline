<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    match: {
        stage1_score: number;
        stage2_score: number | null;
        stage2_rationale: string | null;
        stage2_gaps: Array<{ requirement: string; severity: 'blocker' | 'stretch' | 'minor' }> | null;
        stage2_narrative: string | null;
    };
    showDebug?: boolean;
}>();

const hasRerank = computed(() => props.match.stage2_score !== null && props.match.stage2_rationale !== 'rerank unavailable');

const severityClass = (severity: string) =>
    ({
        blocker: 'bg-oxblood/10 text-oxblood border-oxblood/40',
        stretch: 'bg-bone-2 text-ink-2 border-hairline',
        minor: 'bg-transparent text-ink-3 border-hairline',
    })[severity] ?? 'bg-bone-2 text-ink-3 border-hairline';
</script>

<template>
    <div v-if="hasRerank" class="mt-4 rounded border border-hairline bg-bone px-4 py-3">
        <p class="text-sm leading-relaxed text-ink-2">{{ match.stage2_rationale }}</p>

        <div v-if="match.stage2_gaps && match.stage2_gaps.length" class="mt-3 flex flex-wrap gap-1.5">
            <span
                v-for="(gap, idx) in match.stage2_gaps"
                :key="idx"
                :class="['rounded-full border px-2.5 py-0.5 text-xs font-medium', severityClass(gap.severity)]"
            >
                {{ gap.requirement }}
            </span>
        </div>

        <p v-if="showDebug" class="mt-2 font-mono text-xs text-ink-3">
            stage1 = {{ match.stage1_score.toFixed(4) }} · stage2 = {{ match.stage2_score }}
        </p>
    </div>

    <div v-else class="mt-4 rounded border border-dashed border-hairline bg-bone-2/60 px-4 py-3 text-sm text-ink-3">
        <p v-if="match.stage2_rationale === 'rerank unavailable'" class="font-medium text-ink-2">AI rationale temporarily unavailable</p>
        <p v-else class="font-medium text-ink-2">Analyzing fit…</p>
        <p class="mt-1">
            <span v-if="match.stage2_rationale === 'rerank unavailable'"> Refresh shortly — this usually resolves on the next rerank cycle. </span>
            <span v-else>Career-arc rationale arrives after the rerank completes (seconds to a minute).</span>
        </p>
        <p v-if="showDebug" class="mt-2 font-mono text-xs">stage1 = {{ match.stage1_score.toFixed(4) }}</p>
    </div>
</template>
