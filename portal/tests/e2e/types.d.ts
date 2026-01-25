/**
 * Global type declarations for Puke Portal
 */

interface PushNotificationHandler {
  isInitialized: boolean;
  isSubscribed: boolean;
  initError: string | null;
  init(): Promise<boolean>;
  subscribe(): Promise<boolean>;
  unsubscribe(): Promise<boolean>;
  toggle(): Promise<boolean>;
  isSupported(): boolean;
  getPermissionStatus(): string;
  getSubscriptionStatus(): boolean;
  sendTest(): Promise<{ success: boolean; message: string }>;
}

interface Window {
  pukePush: PushNotificationHandler;
  App?: {
    showToast(message: string, type: string): void;
  };
  BASE_PATH?: string;
}
