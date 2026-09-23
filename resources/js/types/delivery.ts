export type AttemptStatus =
    | 'not_started'
    | 'in_progress'
    | 'paused'
    | 'submitted'
    | 'voided';

export type ProctorSeverity = 'low' | 'medium' | 'high';

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
    proctorEventCount: number;
    proctorHighestSeverity: ProctorSeverity | null;
};

export type ProctorEventType =
    | 'fullscreen_exited'
    | 'tab_hidden'
    | 'copy_attempt'
    | 'paste_attempt'
    | 'right_click'
    | 'print_attempt'
    | 'devtools_opened'
    | 'unknown_device';

export type ProctorEventRow = {
    id: number;
    type: ProctorEventType;
    typeLabel: string;
    severity: ProctorSeverity;
    detail: Record<string, unknown> | null;
    occurredAt: string;
};

export type ProctorDecisionType =
    | 'no_action'
    | 'warning'
    | 'flagged_for_review'
    | 'void_attempt';

export type ProctorDecisionRow = {
    id: number;
    decision: ProctorDecisionType;
    decisionLabel: string;
    reason: string;
    decidedBy: string | null;
    decidedAt: string;
    coversFrom: string | null;
    coversTo: string | null;
};

export type ProctorCase = {
    attempt: {
        id: number;
        candidateNo: string;
        name: string;
        status: AttemptStatus;
        statusLabel: string;
    };
    events: ProctorEventRow[];
    decisions: ProctorDecisionRow[];
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
