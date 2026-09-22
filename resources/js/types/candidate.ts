export type CandidateStatus = 'enrolled' | 'allocated' | 'checked_in';

export type RoomChoice = { id: number; name: string; capacity: number };

export type CentreChoice = {
    id: number;
    name: string;
    code: string;
    rooms: RoomChoice[];
};

export type RoomRow = {
    id: number;
    name: string;
    capacity: number;
    isActive: boolean;
};

export type CentreRow = {
    id: number;
    name: string;
    code: string;
    address: string | null;
    isActive: boolean;
    capacity: number;
    rooms: RoomRow[];
};

export type CentreAbilities = { manage: boolean };

export type CandidateRow = {
    id: number;
    candidateNo: string;
    name: string;
    rollNo: string | null;
    cnic: string | null;
    email: string | null;
    phone: string | null;
    status: CandidateStatus;
    statusLabel: string;
    centre: string | null;
    room: string | null;
    seatNo: string | null;
    extraTimeMinutes: number | null;
    extraTimeReason: string | null;
    hasPin: boolean;
    checkedInAt: string | null;
};

export type CandidateSummary = {
    total: number;
    enrolled: number;
    allocated: number;
    checkedIn: number;
};

export type CandidateAbilities = {
    manage: boolean;
    allocate: boolean;
    checkin: boolean;
    extraTime: boolean;
};

export type ImportResult = {
    imported: number;
    skipped: number;
    errors: { row: number; message: string }[];
};

export type CheckInRow = {
    id: number;
    candidateNo: string;
    name: string;
    rollNo: string | null;
    status: CandidateStatus;
    statusLabel: string;
    centre: string | null;
    room: string | null;
    seatNo: string | null;
    checkedInAt: string | null;
};

export type IssuedPin = { candidateNo: string; pin: string };
