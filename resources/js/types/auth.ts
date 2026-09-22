export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type AuthCan = {
    viewQuestions?: boolean;
    createQuestions?: boolean;
    importQuestions?: boolean;
    reviewQuestions?: boolean;
    approveQuestions?: boolean;
    viewExams?: boolean;
    createExams?: boolean;
    approveBlueprints?: boolean;
};

export type Auth = {
    user: User;
    can: AuthCan;
    /** What is waiting for this person, for the badge in the menu. */
    awaiting?: { blueprints: number };
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
