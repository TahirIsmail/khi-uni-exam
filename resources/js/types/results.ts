export type PublicationStatus = 'draft' | 'approved' | 'published';
export type RekeyDecision = 'discard' | 'correct_option';

export type ResultsExaminationRow = {
    id: number;
    reference: string;
    title: string;
    submittedCount: number;
    status: PublicationStatus;
    statusLabel: string;
};

export type ResultRow = {
    attemptId: number;
    candidateNo: string;
    name: string;
    rawMarks: number;
    negativeDeduction: number;
    totalMarks: number;
    percentage: number;
    isPass: boolean;
    pendingItems: boolean;
};

export type ResultItemOption = { id: number; label: string; body: string };

export type ResultItemRow = {
    id: number;
    position: number;
    marks: number;
    isManuallyMarked: boolean;
    hasItems: boolean;
    options: ResultItemOption[];
    alreadyRekeyed: boolean;
};

export type ResultsPublication = {
    status: PublicationStatus;
    statusLabel: string;
    approvedAt: string | null;
    publishedAt: string | null;
};

export type ResultsAbilities = {
    approve: boolean;
    publish: boolean;
    rescore: boolean;
};
