import axios, { AxiosError, type AxiosRequestConfig } from 'axios';

/** Every API response uses this envelope (see backend App\Support\ApiResponse). */
export interface ApiEnvelope<T> {
  success: boolean;
  message: string;
  data: T;
  meta?: { pagination?: Pagination; [k: string]: unknown };
  errors?: Record<string, string[]>;
  code?: string;
}

export interface Pagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from?: number | null;
  to?: number | null;
}

export interface Paginated<T> {
  items: T[];
  pagination: Pagination;
  meta?: Record<string, unknown>;
}

/** Normalised error the UI can always rely on. */
export class ApiError extends Error {
  status: number;
  code?: string;
  errors: Record<string, string[]>;
  context?: Record<string, unknown>;

  constructor(message: string, status: number, code?: string, errors: Record<string, string[]> = {}, context?: Record<string, unknown>) {
    super(message);
    this.status = status;
    this.code = code;
    this.errors = errors;
    this.context = context;
  }

  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0];
  }
}

export const http = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  timeout: 30000,
});

let csrfReady: Promise<void> | null = null;
/** Sanctum: fetch the XSRF-TOKEN cookie once before the first state-changing request. */
export function ensureCsrf(force = false): Promise<void> {
  if (!csrfReady || force) {
    csrfReady = axios.get('/sanctum/csrf-cookie', { withCredentials: true }).then(() => undefined).catch(() => {
      csrfReady = null;
    });
  }
  return csrfReady;
}

http.interceptors.request.use(async (config) => {
  const method = (config.method ?? 'get').toLowerCase();
  if (!['get', 'head', 'options'].includes(method)) {
    await ensureCsrf();
  }
  return config;
});

function toApiError(error: unknown): ApiError {
  if (error instanceof ApiError) return error;
  const err = error as AxiosError<ApiEnvelope<unknown> & { errors?: Record<string, string[]> & { context?: Record<string, unknown> } }>;
  if (!err.response) {
    return new ApiError(
      err.code === 'ECONNABORTED' ? 'The request took too long. Please check your connection and try again.' : 'You appear to be offline or our servers are unreachable. Please try again.',
      0,
      'network_error',
    );
  }
  const body = err.response.data;
  const errors = (body?.errors ?? {}) as Record<string, string[]>;
  const context = (body?.errors as unknown as { context?: Record<string, unknown> })?.context;
  return new ApiError(body?.message || 'Something went wrong. Please try again.', err.response.status, body?.code, errors, context);
}

http.interceptors.response.use(
  (r) => r,
  async (error: AxiosError) => {
    // CSRF token expired — refresh once and retry.
    if (error.response?.status === 419 && error.config && !(error.config as AxiosRequestConfig & { _retried?: boolean })._retried) {
      (error.config as AxiosRequestConfig & { _retried?: boolean })._retried = true;
      await ensureCsrf(true);
      return http.request(error.config);
    }
    return Promise.reject(toApiError(error));
  },
);

export async function get<T>(url: string, params?: Record<string, unknown>): Promise<T> {
  const { data } = await http.get<ApiEnvelope<T>>(url, { params });
  return data.data;
}

export async function getPage<T>(url: string, params?: Record<string, unknown>): Promise<Paginated<T>> {
  const { data } = await http.get<ApiEnvelope<T[]>>(url, { params });
  return {
    items: data.data,
    pagination: data.meta?.pagination ?? { current_page: 1, last_page: 1, per_page: data.data.length, total: data.data.length },
    meta: data.meta,
  };
}

export async function send<T>(method: 'post' | 'put' | 'patch' | 'delete', url: string, body?: unknown): Promise<{ data: T; message: string }> {
  const { data } = await http.request<ApiEnvelope<T>>({ method, url, data: body });
  return { data: data.data, message: data.message };
}

export const post = <T>(url: string, body?: unknown) => send<T>('post', url, body);
export const put = <T>(url: string, body?: unknown) => send<T>('put', url, body);
export const patch = <T>(url: string, body?: unknown) => send<T>('patch', url, body);
export const del = <T>(url: string, body?: unknown) => send<T>('delete', url, body);

/** Multipart upload helper (files + fields). Arrays use name[] convention. */
export async function upload<T>(url: string, fields: Record<string, unknown>): Promise<{ data: T; message: string }> {
  const form = new FormData();
  Object.entries(fields).forEach(([k, v]) => {
    if (v === undefined || v === null || v === '') return;
    if (Array.isArray(v)) v.forEach((item, i) => form.append(item instanceof File ? `${k}[]` : `${k}[${i}]`, item instanceof File ? item : String(item)));
    else if (v instanceof File) form.append(k, v);
    else if (typeof v === 'boolean') form.append(k, v ? '1' : '0');
    else form.append(k, String(v));
  });
  return send<T>('post', url, form);
}

/** Download a binary (PDF/CSV/XLSX) via the authenticated session. */
export async function download(url: string, filename: string): Promise<void> {
  try {
    const res = await http.get(url, { responseType: 'blob' });
    const href = URL.createObjectURL(res.data as Blob);
    const a = document.createElement('a');
    a.href = href;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(href), 2000);
  } catch (e) {
    const err = e as ApiError;
    if ((err as unknown as { status?: number }).status && err.message) throw err;
    throw new ApiError('Download failed. Please try again.', 0);
  }
}

export function errorMessage(e: unknown, fallback = 'Something went wrong. Please try again.'): string {
  if (e instanceof ApiError) return e.message || fallback;
  if (e instanceof Error) return e.message || fallback;
  return fallback;
}
