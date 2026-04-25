<script setup lang="ts">
import TagInput from '@/components/TagInput.vue';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    profile: {
        id?: number;
        name?: string;
        resume_id?: number | null;
        role_keywords?: string[];
        locations?: string[];
        remote_mode?: string;
        comp_min?: number | null;
        comp_currency?: string | null;
        industries?: string[];
        seniority_levels?: string[];
        excluded_companies?: string[];
        is_active?: boolean;
    };
    resumes: Array<{ id: number; name: string }>;
}>();

const form = useForm({
    name: props.profile.name ?? '',
    resume_id: props.profile.resume_id ?? null,
    role_keywords: props.profile.role_keywords ?? [],
    locations: props.profile.locations ?? [],
    remote_mode: props.profile.remote_mode ?? 'any',
    comp_min: props.profile.comp_min ?? null,
    comp_currency: props.profile.comp_currency ?? 'USD',
    industries: props.profile.industries ?? [],
    seniority_levels: props.profile.seniority_levels ?? [],
    excluded_companies: props.profile.excluded_companies ?? [],
    is_active: props.profile.is_active ?? true,
});

const isEditing = computed(() => !!props.profile.id);

function submit() {
    if (isEditing.value) {
        form.put(route('search-profiles.update', props.profile.id));
    } else {
        form.post(route('search-profiles.store'));
    }
}

function destroyProfile() {
    if (window.confirm('Delete this profile? Saved applications stay; the profile and its match feed go away.')) {
        form.delete(route('search-profiles.destroy', props.profile.id));
    }
}

// Suggestion lists. Users can always type their own; these just speed up the common cases.
const roleSuggestions = [
    'Software Engineer', 'Senior Software Engineer', 'Staff Software Engineer', 'Principal Engineer',
    'Frontend Engineer', 'Backend Engineer', 'Full-Stack Engineer',
    'DevOps Engineer', 'Site Reliability Engineer', 'Platform Engineer', 'Infrastructure Engineer',
    'Data Engineer', 'Data Scientist', 'Machine Learning Engineer', 'AI Engineer',
    'Mobile Engineer', 'iOS Engineer', 'Android Engineer',
    'Engineering Manager', 'Senior Engineering Manager', 'Director of Engineering', 'VP Engineering', 'CTO',
    'Product Manager', 'Senior Product Manager', 'Group Product Manager',
    'Product Designer', 'UX Designer', 'Design Lead',
    'Solutions Engineer', 'Solutions Architect', 'Sales Engineer',
];

const locationSuggestions = [
    'Remote', 'Remote (US)', 'Remote (US/Canada)', 'Remote (EMEA)', 'Remote (Worldwide)',
    'San Francisco, CA', 'New York, NY', 'Seattle, WA', 'Austin, TX', 'Boston, MA',
    'Los Angeles, CA', 'Chicago, IL', 'Denver, CO', 'Portland, OR', 'Atlanta, GA',
    'Washington, DC', 'Toronto, Canada', 'Vancouver, Canada',
    'London, UK', 'Berlin, Germany', 'Amsterdam, Netherlands', 'Dublin, Ireland', 'Paris, France',
];

const industrySuggestions = [
    'SaaS', 'Fintech', 'Healthcare', 'HealthTech', 'E-commerce', 'Media',
    'EdTech', 'ClimateTech', 'Biotech', 'Cybersecurity', 'AI/ML',
    'Crypto/Web3', 'Gaming', 'Developer Tools', 'Open Source',
    'Enterprise', 'B2B', 'B2C', 'Consumer', 'Marketplace', 'Government',
];

const senioritySuggestions = [
    'Junior', 'Mid', 'Senior', 'Staff', 'Principal', 'Distinguished',
    'Lead', 'Manager', 'Senior Manager', 'Director', 'Senior Director',
    'VP', 'SVP', 'C-level',
];

const excludedSuggestions = [
    'Meta', 'Google', 'Amazon', 'Apple', 'Microsoft',
    'Oracle', 'Salesforce', 'IBM', 'Palantir', 'Tesla',
];
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <!-- Identity -->
        <section class="rounded border border-hairline bg-bone-2/30 p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-ink-3">Identity</h2>
            <p class="mt-1 text-xs text-ink-3">A name for your reference, plus the resume we'll match against jobs.</p>

            <div class="mt-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-ink">Name</label>
                    <p class="text-xs text-ink-3">What you'll call this profile, e.g. <span class="font-mono">"Backend senior, Bay Area"</span> or <span class="font-mono">"Remote staff role"</span>.</p>
                    <input v-model="form.name" type="text" required class="mt-1.5 w-full rounded border border-hairline bg-bone px-3 py-2 text-sm focus:border-oxblood focus:outline-none focus:ring-1 focus:ring-oxblood/40" />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-oxblood">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-ink">Linked resume</label>
                    <p class="text-xs text-ink-3">Match scores come from comparing this resume's text to each job. Different profiles can use different resumes.</p>
                    <select v-model="form.resume_id" class="mt-1.5 w-full rounded border border-hairline bg-bone px-3 py-2 text-sm focus:border-oxblood focus:outline-none focus:ring-1 focus:ring-oxblood/40">
                        <option :value="null">— pick a resume —</option>
                        <option v-for="r in resumes" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>
                    <p v-if="form.errors.resume_id" class="mt-1 text-sm text-oxblood">{{ form.errors.resume_id }}</p>
                </div>
            </div>
        </section>

        <!-- Match preferences (semantic hints) -->
        <section class="rounded border border-hairline bg-bone-2/30 p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-ink-3">Match preferences</h2>
            <p class="mt-1 text-xs text-ink-3">
                These are <span class="font-medium text-ink-2">semantic hints</span> — they shape how Claude reasons about fit during the rerank step. They don't filter jobs out, just nudge the ranking.
            </p>

            <div class="mt-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-ink">Role keywords</label>
                    <p class="text-xs text-ink-3">Job titles or function keywords you're targeting. Type and press <kbd class="rounded border border-hairline bg-bone px-1 text-[10px]">Enter</kbd> or <kbd class="rounded border border-hairline bg-bone px-1 text-[10px]">,</kbd> to add. Suggestions appear as you type.</p>
                    <TagInput v-model="form.role_keywords" placeholder="e.g. Senior Software Engineer, Staff Engineer…" :suggestions="roleSuggestions" class="mt-1.5" />
                    <p v-if="form.errors.role_keywords" class="mt-1 text-sm text-oxblood">{{ form.errors.role_keywords }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-ink">Industries</label>
                    <p class="text-xs text-ink-3">Industries you're interested in or already work in. Helps Claude weight relevance.</p>
                    <TagInput v-model="form.industries" placeholder="e.g. SaaS, Fintech, Healthcare…" :suggestions="industrySuggestions" class="mt-1.5" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-ink">Seniority levels</label>
                    <p class="text-xs text-ink-3">Seniority bands that fit your trajectory. Multi-select — typical: <span class="font-mono">Senior, Staff</span>.</p>
                    <TagInput v-model="form.seniority_levels" placeholder="e.g. Senior, Staff, Principal…" :suggestions="senioritySuggestions" class="mt-1.5" />
                </div>
            </div>
        </section>

        <!-- Hard filters -->
        <section class="rounded border border-hairline bg-bone-2/30 p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-ink-3">Hard filters</h2>
            <p class="mt-1 text-xs text-ink-3">
                Applied at the database query level, <span class="font-medium text-ink-2">before</span> ranking. Jobs that don't pass these never reach the feed.
            </p>

            <div class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <label class="block text-sm font-medium text-ink">Remote mode</label>
                        <p class="text-xs text-ink-3">How permissive about location.</p>
                        <select v-model="form.remote_mode" class="mt-1.5 w-full rounded border border-hairline bg-bone px-3 py-2 text-sm focus:border-oxblood focus:outline-none focus:ring-1 focus:ring-oxblood/40">
                            <option value="any">Any (include all)</option>
                            <option value="remote_only">Remote only</option>
                            <option value="hybrid_ok">Remote or hybrid OK</option>
                            <option value="onsite">On-site only</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Min comp</label>
                        <p class="text-xs text-ink-3">Excludes jobs with disclosed comp below this. Most postings don't disclose — those are <em>not</em> excluded.</p>
                        <input v-model.number="form.comp_min" type="number" min="0" step="1000" placeholder="e.g. 150000" class="mt-1.5 w-full rounded border border-hairline bg-bone px-3 py-2 text-sm focus:border-oxblood focus:outline-none focus:ring-1 focus:ring-oxblood/40" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Currency</label>
                        <p class="text-xs text-ink-3">ISO 4217 code.</p>
                        <select v-model="form.comp_currency" class="mt-1.5 w-full rounded border border-hairline bg-bone px-3 py-2 text-sm focus:border-oxblood focus:outline-none focus:ring-1 focus:ring-oxblood/40">
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                            <option value="GBP">GBP</option>
                            <option value="CAD">CAD</option>
                            <option value="AUD">AUD</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-ink">Locations</label>
                    <p class="text-xs text-ink-3">Cities or regions you'd consider. Empty = no location filter beyond remote mode.</p>
                    <TagInput v-model="form.locations" placeholder="e.g. Remote, San Francisco, Berlin…" :suggestions="locationSuggestions" class="mt-1.5" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-ink">Excluded companies</label>
                    <p class="text-xs text-ink-3">Jobs from these companies are filtered out entirely. Useful for past employers, competitors, or values mismatches.</p>
                    <TagInput v-model="form.excluded_companies" placeholder="e.g. Meta, Oracle…" :suggestions="excludedSuggestions" class="mt-1.5" />
                </div>
            </div>
        </section>

        <!-- Activation -->
        <section class="rounded border border-hairline bg-bone-2/30 p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-ink-3">Activation</h2>
            <p class="mt-1 text-xs text-ink-3">Inactive profiles preserve their settings but stop generating new matches. Toggle off to pause without deleting.</p>

            <label class="mt-3 inline-flex items-center gap-2">
                <input v-model="form.is_active" type="checkbox" class="rounded border-hairline" />
                <span class="text-sm text-ink-2">Active — generate matches for this profile</span>
            </label>
        </section>

        <div class="flex items-center justify-between">
            <button v-if="isEditing" type="button" class="text-sm text-ink-3 hover:text-oxblood" @click="destroyProfile">
                Delete profile
            </button>
            <span v-else />
            <button type="submit" class="rounded-full bg-oxblood px-6 py-2 text-sm font-medium text-bone hover:opacity-90 disabled:opacity-50" :disabled="form.processing">
                {{ isEditing ? 'Save changes' : 'Create profile' }}
            </button>
        </div>
    </form>
</template>
