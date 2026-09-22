<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { KeyRound, Search, UserCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import conduct from '@/routes/conduct';
import type {
    CandidateStatus,
    CheckInRow,
    ExaminationDetail,
    IssuedPin,
} from '@/types';

const props = defineProps<{
    examination: ExaminationDetail;
    search: string;
    results: CheckInRow[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Conduct Exam', href: conduct.index() }] },
});

const page = usePage();
const pin = computed(() => (page.flash ?? {}) as { pin?: IssuedPin });

const query = ref(props.search);
function runSearch(): void {
    router.get(
        conduct.checkin(props.examination.id).url,
        query.value === '' ? {} : { search: query.value },
        { preserveState: true, replace: true },
    );
}

function checkIn(candidate: CheckInRow): void {
    router.post(
        conduct.checkin.do([props.examination.id, candidate.id]).url,
        {},
        { preserveScroll: true },
    );
}

function reissue(candidate: CheckInRow): void {
    router.post(
        conduct.checkin.reissuePin([props.examination.id, candidate.id]).url,
        {},
        { preserveScroll: true },
    );
}

const statusStyle: Record<
    CandidateStatus,
    'secondary' | 'outline' | 'default'
> = {
    enrolled: 'secondary',
    allocated: 'outline',
    checked_in: 'default',
};
</script>

<template>
    <Head :title="`Check-in — ${examination.title}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Check-in — ${examination.title}`"
            :description="`${examination.reference} · ${examination.course}`"
        />

        <div
            v-if="pin.pin"
            class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200"
            data-test="issued-pin"
        >
            <span
                >Candidate <strong>{{ pin.pin.candidateNo }}</strong
                >'s exam PIN is
                <strong
                    class="font-mono text-lg tracking-widest"
                    data-test="pin-value"
                    >{{ pin.pin.pin }}</strong
                >
                — tell them now; it is shown here only once.</span
            >
        </div>

        <form
            class="flex flex-wrap items-end gap-2 rounded-xl border p-4 shadow-xs"
            @submit.prevent="runSearch"
        >
            <Input
                v-model="query"
                placeholder="Candidate number, roll number or name"
                class="max-w-sm"
                data-test="checkin-search"
            />
            <Button type="submit" variant="outline" data-test="checkin-find"
                ><Search /> Find</Button
            >
        </form>

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Candidate</th>
                        <th class="px-3 py-2 font-medium">Seat</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="candidate in results"
                        :key="candidate.id"
                        class="border-t align-top"
                        :data-candidate="candidate.id"
                    >
                        <td class="px-3 py-2">
                            <div class="font-mono text-xs">
                                {{ candidate.candidateNo }}
                            </div>
                            <div class="font-medium">{{ candidate.name }}</div>
                            <div
                                v-if="candidate.rollNo"
                                class="text-muted-foreground text-xs"
                            >
                                Roll {{ candidate.rollNo }}
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            <span v-if="candidate.room"
                                >{{ candidate.centre }} — {{ candidate.room
                                }}<span v-if="candidate.seatNo">
                                    · {{ candidate.seatNo }}</span
                                ></span
                            >
                            <span v-else class="text-muted-foreground"
                                >Not seated</span
                            >
                        </td>
                        <td class="px-3 py-2">
                            <Badge :variant="statusStyle[candidate.status]">{{
                                candidate.statusLabel
                            }}</Badge>
                        </td>
                        <td class="px-3 py-2">
                            <Button
                                v-if="candidate.status === 'allocated'"
                                size="sm"
                                data-test="check-in"
                                @click="checkIn(candidate)"
                            >
                                <UserCheck /> Check in &amp; issue PIN
                            </Button>
                            <Button
                                v-else-if="candidate.status === 'checked_in'"
                                size="sm"
                                variant="outline"
                                data-test="reissue-pin"
                                @click="reissue(candidate)"
                            >
                                <KeyRound /> Reissue PIN
                            </Button>
                            <span v-else class="text-muted-foreground text-xs"
                                >Needs a seat first</span
                            >
                        </td>
                    </tr>
                    <tr v-if="results.length === 0 && search !== ''">
                        <td
                            colspan="4"
                            class="text-muted-foreground px-3 py-10 text-center"
                        >
                            No candidate matches "{{ search }}".
                        </td>
                    </tr>
                    <tr v-if="search === ''">
                        <td
                            colspan="4"
                            class="text-muted-foreground px-3 py-10 text-center"
                        >
                            Search for a candidate to check them in.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
