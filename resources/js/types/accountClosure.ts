export type AccountClosureReview = {
    id: number;
    name: string;
    role: string;
    source_token: string | null;
    outcome: 'retained' | 'deleted';
    allowed: boolean;
    blockers: { code: string; message: string; next: string; ids: number[] }[];
    work: { id: number; number: string; status: string; total: string; payment_method: string }[];
    references: { table: string; count: number }[];
    closure: { id: number; outcome: string; reason: string; actor_name: string; closed_at: string } | null;
    retention_note: string;
};
