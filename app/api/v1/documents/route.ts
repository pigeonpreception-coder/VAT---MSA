import { handleDocumentUpload } from "@/lib/api/platform";

export async function POST(request: Request) { return handleDocumentUpload(request); }
