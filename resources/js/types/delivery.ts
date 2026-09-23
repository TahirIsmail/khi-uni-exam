export type AttemptStatus =
    | 'not_started'
    | 'in_progress'
    | 'paused'
    | 'submitted';

export type MonitorRow = {
    id: number;
    candidateNo: string;
    name: string;
    roomId: number | null;
    room: string | null;
    status: AttemptStatus;
    statusLabel: string;
    startedAt: string | null;
    remainingSeconds: number | null;
    hasOpenSession: boolean;
    heartbeatAgeSeconds: number | null;
    sessionAlive: boolean;
};

export type ItemAnswerKind =
    | 'none'
    | 'boolean'
    | 'option'
    | 'text'
    | 'position';

export type AttemptOption = { id: number; label: string; body: string };
export type AttemptSubItem = { id: number; body: string };

export type AnswerPayload = {
    selected?: number[];
    items?: Record<number, boolean | number>;
    order?: number[];
    text?: string;
};

export type AttemptItem = {
    id: number;
    position: number;
    marks: number;
    typeCode: string | null;
    hasOptions: boolean;
    hasItems: boolean;
    itemAnswer: ItemAnswerKind;
    hasAcceptedAnswers: boolean;
    isManuallyMarked: boolean;
    correctMax: number | null;
    vignette: string | null;
    stem: string;
    leadIn: string | null;
    options: AttemptOption[];
    items: AttemptSubItem[];
    answer: AnswerPayload | null;
    flagged: boolean;
};

export type AttemptState = {
    status: AttemptStatus;
    startedAt: string | null;
    deadlineAt: string | null;
    remainingSeconds: number | null;
    lastItemId: number | null;
};
