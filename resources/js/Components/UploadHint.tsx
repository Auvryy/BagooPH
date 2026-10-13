type UploadHintProps = {
    maxMb?: number;
    formats?: string;
    id?: string;
};

export default function UploadHint({ maxMb = 5, formats = 'JPG, PNG, WEBP or PDF', id }: UploadHintProps) {
    return <span id={id} className="mt-1 block text-xs font-normal normal-case text-slate-500">{formats}. Maximum {maxMb} MB per file.</span>;
}
