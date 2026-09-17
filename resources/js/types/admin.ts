export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

export type RoleRow = {
    id: number;
    name: string;
    isSuperAdmin: boolean;
    staffCount: number;
    permissions: string[];
};

export type PermissionGroup = {
    name: string;
    permissions: {
        code: string;
        description: string;
        privileged: boolean;
        grantable: boolean;
    }[];
};

export type StaffRow = {
    staffId: number;
    employeeId: string;
    name: string;
    email: string;
    branch: string | null;
    signedIn: boolean;
    lastLoginAt: string | null;
    scopeCount: number;
    isActive: boolean;
};

export type ScopeType = 'all' | 'programme' | 'professional' | 'course';

export type StaffScope = {
    id: number;
    type: ScopeType;
    label: string;
    grantedBy: string | null;
    createdAt: string | null;
};

export type ScopeOption = {
    type: Exclude<ScopeType, 'all'>;
    id: number;
    label: string;
};

export type AuditEntry = {
    id: number;
    occurredAt: string;
    actorType: string;
    actorName: string | null;
    actorEmail: string | null;
    action: string;
    entityType: string | null;
    entityId: string | null;
    branchId: number | null;
    oldValues: unknown;
    newValues: unknown;
    reason: string | null;
    ip: string | null;
    requestId: string | null;
};

export type AuditFilters = {
    action: string | null;
    actor: string | null;
    entity_type: string | null;
    entity_id: string | null;
    from: string | null;
    to: string | null;
};

export type AuditVerification = {
    ok: boolean;
    checked: number;
    first_broken_id: number | null;
    problem: string | null;
};
