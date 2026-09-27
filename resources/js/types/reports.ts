export type CohortDescription = {
    programmeId: number;
    professionalId: number;
    intakeId: number;
    termId: number | null;
    programme: string | null;
    year: string | null;
    intake: string | null;
};

export type SheetCourse = {
    examinationId: number;
    reference: string;
    code: string;
    title: string;
    totalMarks: number;
    passPercentage: number;
    creditHours: number | null;
};

export type SheetCourseResult = {
    totalMarks: number | null;
    percentage: number | null;
    grade: string | null;
    gradePoint: number | null;
    isPass: boolean | null;
    pending: boolean;
};

export type SheetCandidate = {
    candidateNo: string;
    name: string;
    rollNo: string | null;
    identityClash: boolean;
    courses: Record<number, SheetCourseResult>;
    obtainedMarks: number;
    possibleMarks: number;
    percentage: number | null;
    gpa: number | null;
    creditHours: number | null;
    satEverything: boolean;
    isPass: boolean;
    position: number | null;
};

export type TabulationSheetData = {
    calendarType: string | null;
    courses: SheetCourse[];
    candidates: SheetCandidate[];
    awaiting: { reference: string; title: string }[];
    creditHoursMissing: string[];
};

export type CandidateStatementData = {
    candidate: SheetCandidate;
    courses: SheetCourse[];
    calendarType: string | null;
    awaiting: { reference: string; title: string }[];
    creditHoursMissing: string[];
    cumulative: {
        cgpa: number | null;
        creditHours: number;
        terms: {
            termId: number | null;
            name: string | null;
            gpa: number | null;
            creditHours: number | null;
        }[];
    } | null;
    place: {
        programme: string | null;
        year: string | null;
        intake: string | null;
    };
};
