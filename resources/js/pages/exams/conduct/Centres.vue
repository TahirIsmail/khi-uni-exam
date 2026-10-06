<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { DoorOpen, Laptop, Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import conduct from '@/routes/conduct';
import type { CentreAbilities, CentreRow, PendingDevice } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Conduct Exam', href: conduct.index() },
            { title: 'Centres & rooms', href: conduct.centres() },
        ],
    },
});

const props = defineProps<{
    centres: CentreRow[];
    can: CentreAbilities;
}>();

const showNewCentre = ref(false);
const centreForm = useForm({
    name: '',
    code: '',
    address: '',
    is_active: true,
});
function addCentre(): void {
    centreForm.post(conduct.centres.store().url, {
        preserveScroll: true,
        onSuccess: () => {
            centreForm.reset();
            showNewCentre.value = false;
        },
    });
}

const editingCentre = ref<number | null>(null);
const editCentreForm = useForm({
    name: '',
    code: '',
    address: '',
    is_active: true,
});
function startEditCentre(centre: CentreRow): void {
    editingCentre.value = centre.id;
    editCentreForm.name = centre.name;
    editCentreForm.code = centre.code;
    editCentreForm.address = centre.address ?? '';
    editCentreForm.is_active = centre.isActive;
}
function saveCentre(centre: CentreRow): void {
    editCentreForm.put(conduct.centres.update(centre.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            editingCentre.value = null;
        },
    });
}

const showNewRoom = ref<number | null>(null);
const roomForm = useForm({ name: '', capacity: 30, is_active: true });
function addRoom(centre: CentreRow): void {
    roomForm.post(conduct.rooms.store(centre.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            roomForm.reset();
            showNewRoom.value = null;
        },
    });
}

const editingRoom = ref<number | null>(null);
const editRoomForm = useForm({ name: '', capacity: 30, is_active: true });
function startEditRoom(room: {
    id: number;
    name: string;
    capacity: number;
    isActive: boolean;
}): void {
    editingRoom.value = room.id;
    editRoomForm.name = room.name;
    editRoomForm.capacity = room.capacity;
    editRoomForm.is_active = room.isActive;
}
function saveRoom(centre: CentreRow, roomId: number): void {
    editRoomForm.put(conduct.rooms.update([centre.id, roomId]).url, {
        preserveScroll: true,
        onSuccess: () => {
            editingRoom.value = null;
        },
    });
}

// ---- centre device approval (step 19): folded away until there is something to approve --------
const openDevices = ref<number | null>(null);
const pendingDevices = ref<Record<number, PendingDevice[]>>({});
async function toggleDevices(centre: CentreRow): Promise<void> {
    if (openDevices.value === centre.id) {
        openDevices.value = null;

        return;
    }
    openDevices.value = centre.id;
    const response = await fetch(conduct.centres.devices(centre.id).url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    const data = (await response.json()) as { devices: PendingDevice[] };
    pendingDevices.value = {
        ...pendingDevices.value,
        [centre.id]: data.devices,
    };
}
function approveAllDevices(centre: CentreRow): void {
    router.post(
        conduct.centres.devices.approveAll(centre.id).url,
        {},
        { preserveScroll: true },
    );
}

function approveDevice(centre: CentreRow, device: PendingDevice): void {
    router.post(
        conduct.centres.devices.approve([centre.id, device.id]).url,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                pendingDevices.value = {
                    ...pendingDevices.value,
                    [centre.id]: (pendingDevices.value[centre.id] ?? []).filter(
                        (d) => d.id !== device.id,
                    ),
                };
            },
        },
    );
}
</script>

<template>
    <Head title="Centres & rooms" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Centres & rooms"
                description="Where candidates sit, and how many each room holds. Shared by every examination on this campus."
            />
            <Button
                v-if="can.manage"
                variant="outline"
                data-test="new-centre"
                @click="showNewCentre = !showNewCentre"
            >
                <Plus /> New centre
            </Button>
        </div>

        <form
            v-if="showNewCentre"
            class="grid gap-3 rounded-xl border p-4 shadow-xs sm:grid-cols-2"
            data-test="new-centre-form"
            @submit.prevent="addCentre"
        >
            <div class="grid gap-1.5">
                <Label for="c-name">Name</Label>
                <Input
                    id="c-name"
                    v-model="centreForm.name"
                    maxlength="150"
                    data-test="centre-name"
                />
                <p
                    v-if="centreForm.errors.name"
                    class="text-destructive text-sm"
                >
                    {{ centreForm.errors.name }}
                </p>
            </div>
            <div class="grid gap-1.5">
                <Label for="c-code">Code</Label>
                <Input
                    id="c-code"
                    v-model="centreForm.code"
                    maxlength="30"
                    data-test="centre-code"
                />
                <p
                    v-if="centreForm.errors.code"
                    class="text-destructive text-sm"
                >
                    {{ centreForm.errors.code }}
                </p>
            </div>
            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="c-address">Address</Label>
                <Input
                    id="c-address"
                    v-model="centreForm.address"
                    maxlength="300"
                />
            </div>
            <Button
                type="submit"
                class="justify-self-start"
                :disabled="centreForm.processing"
                data-test="save-centre"
                >Add centre</Button
            >
        </form>

        <div
            v-for="centre in centres"
            :key="centre.id"
            class="rounded-xl border shadow-xs"
            :data-centre="centre.id"
        >
            <header
                class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
            >
                <template v-if="editingCentre === centre.id">
                    <form
                        class="grid flex-1 gap-2 sm:grid-cols-3"
                        @submit.prevent="saveCentre(centre)"
                    >
                        <Input
                            v-model="editCentreForm.name"
                            maxlength="150"
                            data-test="edit-centre-name"
                        />
                        <Input
                            v-model="editCentreForm.code"
                            maxlength="30"
                            data-test="edit-centre-code"
                        />
                        <Input
                            v-model="editCentreForm.address"
                            maxlength="300"
                            placeholder="Address"
                        />
                        <div class="flex gap-2 sm:col-span-3">
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="editCentreForm.processing"
                                data-test="save-centre-edit"
                                >Save</Button
                            >
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                @click="editingCentre = null"
                                >Cancel</Button
                            >
                        </div>
                    </form>
                </template>
                <template v-else>
                    <div>
                        <span class="font-medium">{{ centre.name }}</span>
                        <span
                            class="text-muted-foreground ml-2 font-mono text-xs"
                            >{{ centre.code }}</span
                        >
                        <Badge
                            v-if="!centre.isActive"
                            variant="secondary"
                            class="ml-2"
                            >Inactive</Badge
                        >
                        <div
                            v-if="centre.address"
                            class="text-muted-foreground text-xs"
                        >
                            {{ centre.address }}
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-muted-foreground text-xs"
                            >{{ centre.capacity }} seats</span
                        >
                        <Button
                            v-if="centre.devicesPendingCount > 0"
                            size="sm"
                            variant="outline"
                            data-test="toggle-devices"
                            @click="toggleDevices(centre)"
                        >
                            <Laptop class="size-3" />
                            {{ centre.devicesPendingCount }} device(s) awaiting
                            approval
                        </Button>
                        <Button
                            v-if="can.manage && centre.devicesPendingCount > 0"
                            size="sm"
                            data-test="approve-all-devices"
                            @click="approveAllDevices(centre)"
                        >
                            Approve all
                        </Button>
                        <Button
                            v-if="can.manage"
                            size="sm"
                            variant="ghost"
                            data-test="edit-centre"
                            @click="startEditCentre(centre)"
                        >
                            <Pencil />
                        </Button>
                    </div>
                </template>
            </header>

            <div class="grid gap-3 p-4">
                <div
                    v-if="openDevices === centre.id"
                    class="grid gap-2 rounded-lg border p-3"
                    data-test="devices-list"
                >
                    <p
                        v-if="(pendingDevices[centre.id] ?? []).length === 0"
                        class="text-muted-foreground text-sm"
                    >
                        Nothing waiting.
                    </p>
                    <div
                        v-for="device in pendingDevices[centre.id] ?? []"
                        :key="device.id"
                        class="flex items-center justify-between gap-2 text-sm"
                        :data-device="device.id"
                    >
                        <div>
                            <span class="font-mono text-xs"
                                >{{ device.fingerprint }}…</span
                            >
                            <span class="text-muted-foreground ml-2"
                                >first seen with
                                {{ device.firstSeenCandidate }}</span
                            >
                        </div>
                        <Button
                            size="sm"
                            data-test="approve-device"
                            @click="approveDevice(centre, device)"
                            >Approve</Button
                        >
                    </div>
                </div>

                <table
                    v-if="centre.rooms.length > 0"
                    class="w-full text-left text-sm"
                >
                    <thead class="text-muted-foreground">
                        <tr>
                            <th class="py-1 font-medium">Room</th>
                            <th class="py-1 font-medium">Capacity</th>
                            <th class="py-1 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="room in centre.rooms"
                            :key="room.id"
                            class="border-t"
                            :data-room="room.id"
                        >
                            <template v-if="editingRoom === room.id">
                                <td class="py-1">
                                    <Input
                                        v-model="editRoomForm.name"
                                        maxlength="100"
                                        data-test="edit-room-name"
                                    />
                                </td>
                                <td class="py-1">
                                    <Input
                                        v-model="editRoomForm.capacity"
                                        type="number"
                                        min="1"
                                        max="2000"
                                        data-test="edit-room-capacity"
                                    />
                                </td>
                                <td class="py-1">
                                    <Button
                                        size="sm"
                                        :disabled="editRoomForm.processing"
                                        data-test="save-room-edit"
                                        @click="saveRoom(centre, room.id)"
                                        >Save</Button
                                    >
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        @click="editingRoom = null"
                                        >Cancel</Button
                                    >
                                </td>
                            </template>
                            <template v-else>
                                <td class="py-1">
                                    {{ room.name }}
                                    <Badge
                                        v-if="!room.isActive"
                                        variant="secondary"
                                        class="ml-1"
                                        >Inactive</Badge
                                    >
                                </td>
                                <td class="py-1 tabular-nums">
                                    {{ room.capacity }}
                                </td>
                                <td class="py-1">
                                    <Button
                                        v-if="can.manage"
                                        size="sm"
                                        variant="ghost"
                                        data-test="edit-room"
                                        @click="startEditRoom(room)"
                                    >
                                        <Pencil />
                                    </Button>
                                </td>
                            </template>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="text-muted-foreground text-sm">
                    No rooms yet.
                </p>

                <Button
                    v-if="can.manage"
                    size="sm"
                    variant="outline"
                    class="justify-self-start"
                    data-test="new-room"
                    @click="
                        showNewRoom =
                            showNewRoom === centre.id ? null : centre.id
                    "
                >
                    <DoorOpen /> Add room
                </Button>
                <form
                    v-if="showNewRoom === centre.id"
                    class="flex flex-wrap items-end gap-2"
                    data-test="new-room-form"
                    @submit.prevent="addRoom(centre)"
                >
                    <div class="grid gap-1.5">
                        <Label :for="`r-name-${centre.id}`">Name</Label>
                        <Input
                            :id="`r-name-${centre.id}`"
                            v-model="roomForm.name"
                            maxlength="100"
                            data-test="room-name"
                        />
                        <p
                            v-if="roomForm.errors.name"
                            class="text-destructive text-sm"
                        >
                            {{ roomForm.errors.name }}
                        </p>
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`r-cap-${centre.id}`">Capacity</Label>
                        <Input
                            :id="`r-cap-${centre.id}`"
                            v-model="roomForm.capacity"
                            type="number"
                            min="1"
                            max="2000"
                            data-test="room-capacity"
                        />
                    </div>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="roomForm.processing"
                        data-test="save-room"
                        >Add</Button
                    >
                </form>
            </div>
        </div>

        <p v-if="centres.length === 0" class="text-muted-foreground text-sm">
            No centres set up on this campus yet.
        </p>
    </div>
</template>
