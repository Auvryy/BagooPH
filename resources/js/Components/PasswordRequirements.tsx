import { passwordRequirements } from '@/utils/passwordPolicy';

export default function PasswordRequirements() {
    return <p className="mt-1 text-xs text-slate-500">{passwordRequirements}</p>;
}
