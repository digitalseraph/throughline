<script setup lang="ts">
import WaypointThread from '@/components/brand/WaypointThread.vue';
import Wordmark from '@/components/brand/Wordmark.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    siteName: string;
    tagline: string;
}>();

const page = usePage();
const authUser = computed<{ id: number; name: string } | null>(
    () => (page.props as any).auth?.user ?? null,
);
</script>

<template>
    <Head :title="`About — ${siteName}`" />

    <div class="min-h-screen bg-bone text-ink flex flex-col">
        <header class="flex items-center justify-between px-8 py-6">
            <Link href="/" class="inline-flex"><Wordmark size="sm" /></Link>
            <nav class="flex items-center gap-6 font-mono text-xs uppercase tracking-widest text-ink-3">
                <Link href="/about" class="text-oxblood">About</Link>
                <Link href="/help" class="hover:text-oxblood transition-colors">Help</Link>
                <template v-if="authUser">
                    <Link :href="route('dashboard')" class="hover:text-oxblood transition-colors">Dashboard</Link>
                </template>
                <template v-else>
                    <Link href="/login" class="hover:text-oxblood transition-colors">Sign in</Link>
                    <Link href="/register" class="hover:text-oxblood transition-colors">Create account</Link>
                </template>
            </nav>
        </header>

        <main class="flex-1 px-8 pb-20">
            <section class="mx-auto max-w-3xl text-center pt-10 pb-16">
                <p class="font-mono text-[11px] uppercase tracking-widest text-oxblood mb-6">About</p>
                <h1
                    class="font-display italic text-5xl leading-tight text-ink mb-6"
                    style="font-variation-settings: 'opsz' 144, 'SOFT' 40;"
                >
                    {{ tagline }}
                </h1>
                <p class="font-sans text-base text-ink-2 leading-relaxed">
                    {{ siteName }} is a job-discovery tool for people whose careers already have a shape — and who want their next role to be consistent with that shape.
                </p>
            </section>

            <section class="mx-auto max-w-3xl border-t border-hairline pt-14 pb-14">
                <h2 class="font-display text-3xl text-ink mb-6">Why this exists</h2>
                <div class="space-y-5 font-sans text-ink-2 leading-relaxed">
                    <p>
                        Most job search tools optimize for volume. They let you fire off hundreds of applications, auto-tailor cover letters, and celebrate response rates as if response rate were the point.
                    </p>
                    <p>
                        The point, for the kind of person this is built for, is not volume. It's fit. A role that matches the arc of your career gets you a better interview, a better negotiation, and a better first six months than a role that happens to say yes. We built {{ siteName }} to make that kind of role easier to find.
                    </p>
                    <p>
                        You upload your resume. We read it, embed it, and match it against a live database of openings. For the top candidates, <span class="italic">Claude</span> produces an honest fit score, a one-sentence narrative explaining how this role connects to what you've already done, and a structured list of gaps — so you see the pitch and the reality side by side.
                    </p>
                </div>
            </section>

            <section class="mx-auto max-w-3xl border-t border-hairline pt-14 pb-14">
                <h2 class="font-display text-3xl text-ink mb-8">What we believe</h2>

                <div class="space-y-10">
                    <div>
                        <p class="font-mono text-[11px] uppercase tracking-widest text-oxblood mb-2">Considered, not convenient</p>
                        <h3 class="font-display text-xl text-ink mb-2">Your career isn't a funnel</h3>
                        <p class="font-sans text-ink-2 leading-relaxed">
                            A career is a story you're telling yourself. Each role you take bends the arc. Our job isn't to help you apply to more things — it's to help you see which openings fit the arc you're already writing, and which ones would bend it somewhere you don't want to go.
                        </p>
                    </div>

                    <div>
                        <p class="font-mono text-[11px] uppercase tracking-widest text-oxblood mb-2">Honest over flattering</p>
                        <h3 class="font-display text-xl text-ink mb-2">A match tool that tells you no</h3>
                        <p class="font-sans text-ink-2 leading-relaxed">
                            If a role is a stretch, we say so. If there's a blocker, we flag it in red. If your resume doesn't actually cover what the posting wants, you'll see it laid out plainly. A tool that tells you yes to everything isn't helping — it's just generating noise you have to sift.
                        </p>
                    </div>

                    <div>
                        <p class="font-mono text-[11px] uppercase tracking-widest text-oxblood mb-2">Small inputs, real leverage</p>
                        <h3 class="font-display text-xl text-ink mb-2">Structured rationale, not vibes</h3>
                        <p class="font-sans text-ink-2 leading-relaxed">
                            Every feed card shows a narrative, a rationale, and a set of gap chips. Three fields, not a wall of AI text. Each has a different job in your decision-making — triage, consideration, and dealbreaker checks — and you learn to read them quickly.
                        </p>
                    </div>

                    <div>
                        <p class="font-mono text-[11px] uppercase tracking-widest text-oxblood mb-2">Own your process</p>
                        <h3 class="font-display text-xl text-ink mb-2">Every application is a deliberate click</h3>
                        <p class="font-sans text-ink-2 leading-relaxed">
                            We don't auto-apply. We don't tailor your resume per posting. We don't write cover letters. The parts that require judgment stay with you — the machine gets out of the way once it's done the ranking.
                        </p>
                    </div>
                </div>
            </section>

            <section class="mx-auto max-w-3xl border-t border-hairline pt-14 pb-14">
                <h2 class="font-display text-3xl text-ink mb-6">What we don't do</h2>
                <ul class="space-y-3 font-sans text-ink-2 leading-relaxed">
                    <li class="flex gap-3">
                        <span class="mt-1 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-oxblood"></span>
                        <span><span class="text-ink font-medium">Auto-apply.</span> Every application is a click you made on purpose. Not on our roadmap.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-1 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-oxblood"></span>
                        <span><span class="text-ink font-medium">Resume tailoring per posting.</span> Bring your own resumes — you can have as many variants as your career has arcs.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-1 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-oxblood"></span>
                        <span><span class="text-ink font-medium">Ad networks, analytics trackers, data brokers.</span> None of that ships with this product.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-1 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-oxblood"></span>
                        <span><span class="text-ink font-medium">Flatter you into applying.</span> We surface gaps honestly. That's the feature, not a bug.</span>
                    </li>
                </ul>
            </section>

            <section class="mx-auto max-w-3xl border-t border-hairline pt-14 pb-14">
                <h2 class="font-display text-3xl text-ink mb-6">How your data is handled</h2>
                <div class="space-y-5 font-sans text-ink-2 leading-relaxed">
                    <p>
                        Your resume text goes to OpenAI for embedding and to Anthropic (Claude) for parsing and rerank. Job postings go to the same two services, paired with your resume when we rank them. That's the extent of what leaves our database.
                    </p>
                    <p>
                        No third-party trackers. No ad-tech. No selling your career history to recruiters. The only reason your data leaves our servers is to run the matching you came here for.
                    </p>
                    <p>
                        The code is open source — <a href="https://github.com/digitalseraph/throughline" target="_blank" rel="noopener" class="text-oxblood underline-offset-4 hover:underline">available on GitHub</a>. You can read the services, check what gets sent where, and self-host your own install if you'd rather operate it yourself.
                    </p>
                </div>
            </section>

            <section class="mx-auto max-w-3xl border-t border-hairline pt-14 pb-14">
                <h2 class="font-display text-3xl text-ink mb-6">Who builds this</h2>
                <div class="space-y-5 font-sans text-ink-2 leading-relaxed">
                    <p>
                        {{ siteName }} is built by <a href="https://github.com/digitalseraph" target="_blank" rel="noopener" class="text-oxblood underline-offset-4 hover:underline">digitalseraph</a>, the same author who maintains the codebase it's extracted from. It's one part of a personal engineering ecosystem and one part a tool the author actually uses to job-hunt — which is why honesty and restraint win over volume and persuasion.
                    </p>
                    <p>
                        If you run into bugs, have a feature in mind, or want to share what you wish the feed did differently, the <a href="https://github.com/digitalseraph/throughline/issues" target="_blank" rel="noopener" class="text-oxblood underline-offset-4 hover:underline">GitHub issue tracker</a> is the right place to land it.
                    </p>
                </div>
            </section>

            <section class="mx-auto max-w-3xl border-t border-hairline pt-14 text-center">
                <h2 class="font-display text-3xl text-ink mb-6">Start with a resume</h2>
                <p class="font-sans text-ink-2 leading-relaxed mb-8">
                    The whole thing works from a single upload. Ten minutes of setup, and you'll see whether the fit you're chasing is actually out there.
                </p>
                <div class="flex justify-center gap-4">
                    <template v-if="authUser">
                        <Link
                            :href="route('dashboard')"
                            class="inline-flex items-center justify-center h-11 px-6 rounded-md bg-oxblood text-bone font-medium hover:opacity-90 transition-opacity"
                        >
                            Go to your dashboard
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            href="/register"
                            class="inline-flex items-center justify-center h-11 px-6 rounded-md bg-oxblood text-bone font-medium hover:opacity-90 transition-opacity"
                        >
                            Create account
                        </Link>
                        <Link
                            href="/help/getting-started/01-what-is-throughline"
                            class="inline-flex items-center justify-center h-11 px-6 rounded-md border border-hairline text-ink hover:bg-bone-2 transition-colors"
                        >
                            Read the docs
                        </Link>
                    </template>
                </div>
            </section>
        </main>

        <footer class="flex items-center justify-center gap-6 py-8 font-mono text-[10px] uppercase tracking-widest text-ink-3">
            <span>{{ siteName }}</span>
            <WaypointThread :width="120" :height="6" :dot-count="5" />
            <span>Since 2026</span>
        </footer>
    </div>
</template>
