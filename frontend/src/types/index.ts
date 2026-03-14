export interface User {
  id: string;
  email: string;
  company_name: string | null;
  is_active: boolean;
}

export interface TokenResponse {
  access_token: string;
  refresh_token: string;
  token_type: string;
}

export interface Customer {
  id: string;
  name: string;
  email: string | null;
  lexoffice_id: string | null;
  stripe_customer_id: string | null;
}

export interface Invoice {
  id: string;
  customer_id: string;
  invoice_number: string | null;
  amount: number;
  currency: string;
  status: string;
  due_date: string | null;
}

export interface Collection {
  id: string;
  invoice_id: string;
  mandate_id: string;
  amount: number;
  currency: string;
  status: string;
  error_message: string | null;
}
