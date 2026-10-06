<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import reports from '@/routes/reports';

type Option = { id: number; name: string };
type Year = {
    professional_id: number;
    term_id: number | null;
    name: string;
};

const props = defineProps<{
    programmes: Option[];
    years: Year[];
    intakes: Option[];
}>();

const programmeId = ref<number | null>(props.programmes[0]?.id ?? null);
const yearKey = ref<string>('');
const intakeId = ref<number | null>(props.intakes[0]?.id ?? null);

const chosenYear = computed<Year | undefined>(() =>
    props.years.find(
        (year) => `${year.professional_id}:${year.term_id ?? ''}` === yearKey.value,
    ),
);

const ready = computed(
    () =>
        programmeId.value !== null &&
        intakeId.value !== null &&
        chosenYear.value !== undefined,
);

function open(): void {
    if (!ready.value || chosenYear.value === undefined) {
        return;
    }

    router.get(reports.tabulation.url(), {
        programme_id: programmeId.value,
        professional_id: chosenYear.value.professional_id,
        term_id: chosenYear.value.term_id ?? undefined,
        intake_id: intakeId.value,
    });
}
</script>

<template>
    <Head title="Result sheets" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Result sheets"
            description="The tabulation sheet of a class, and each candidate's marks certificate."
        />

        <div class="grid max-w-3xl gap-4 rounded-xl border p-4 shadow-xs sm:grid-cols-3">
            <div class="flex flex-col gap-2">
                <Label for="programme">Programme</Label>
                <select
                    id="programme"
                    v-model="programmeId"
                    class="border-input h-9 rounded-md border px-3 text-sm"
                    data-test="programme"
                >
                    <option
                        v-for="programme in programmes"
                        :key="programme.id"
                        :value="programme.id"
                    >
                        {{ programme.name }}
                    </option>
                </select>
            </div>

            <div class="flex flex-col gap-2">
                <Label for="year">Year / semester</Label>
                <select
                    id="year"
                    v-model="yearKey"
                    class="border-input h-9 rounded-md border px-3 text-sm"
                    data-test="year"
                >
                    <option value="">Choose…</option>
                    <option
                        v-for="year in years"
                        :key="`${year.professional_id}:${year.term_id ?? ''}`"
                        :value="`${year.professional_id}:${year.term_id ?? ''}`"
                    >
                        {{ year.name }}
                    </option>
                </select>
            </div>

            <div class="flex flex-col gap-2">
                <Label for="intake">Intake</Label>
                <select
                    id="intake"
                    v-model="intakeId"
                    class="border-input h-9 rounded-md border px-3 text-sm"
                    data-test="intake"
                >
                    <option
                        v-for="intake in intakes"
                        :key="intake.id"
                        :value="intake.id"
                    >
                        {{ intake.name }}
                    </option>
                </select>
            </div>

            <div class="sm:col-span-3">
                <Button :disabled="!ready" data-test="open-sheet" @click="open">
                    Open the sheet
                </Button>
            </div>
        </div>
    </div>
</template>
