import { lazy } from 'react';
import { createBrowserRouter } from 'react-router-dom';
import { AccountLayout, AdminLayout, GuestOnly, PartnerLayout, PublicLayout, RequireAuth } from '@/components/layout/layouts';
import { RouteError } from '@/pages/RouteError';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const p = <T extends React.ComponentType<any>>(loader: () => Promise<{ default: T }>) => lazy(loader);

// Public
const Home = p(() => import('@/pages/Home'));
const HotelSearch = p(() => import('@/pages/hotels/HotelSearch'));
const HotelDetails = p(() => import('@/pages/hotels/HotelDetails'));
const Tours = p(() => import('@/pages/tours/Tours'));
const TourDetails = p(() => import('@/pages/tours/TourDetails'));
const TourBuilderLanding = p(() => import('@/pages/builder/TourBuilderLanding'));
const TripEditor = p(() => import('@/pages/builder/TripEditor'));
const SharedTrip = p(() => import('@/pages/builder/SharedTrip'));
const Membership = p(() => import('@/pages/Membership'));
const Destinations = p(() => import('@/pages/destinations/Destinations'));
const DestinationDetail = p(() => import('@/pages/destinations/DestinationDetail'));
const Offers = p(() => import('@/pages/Offers'));
const About = p(() => import('@/pages/About'));
const Contact = p(() => import('@/pages/Contact'));
const Faq = p(() => import('@/pages/Faq'));
const Login = p(() => import('@/pages/auth/Login'));
const Register = p(() => import('@/pages/auth/Register'));
const ForgotPassword = p(() => import('@/pages/auth/ForgotPassword'));
const ResetPassword = p(() => import('@/pages/auth/ResetPassword'));
const VerifyEmail = p(() => import('@/pages/auth/VerifyEmail'));
const NotFound = p(() => import('@/pages/NotFound'));

// Checkout
const HotelCheckout = p(() => import('@/pages/checkout/HotelCheckout'));
const TripCheckout = p(() => import('@/pages/checkout/TripCheckout'));
const MembershipCheckout = p(() => import('@/pages/checkout/MembershipCheckout'));
const SandboxCheckout = p(() => import('@/pages/checkout/SandboxCheckout'));
const PaymentStatus = p(() => import('@/pages/checkout/PaymentStatus'));

// Account
const Dashboard = p(() => import('@/pages/account/Dashboard'));
const Bookings = p(() => import('@/pages/account/Bookings'));
const BookingDetail = p(() => import('@/pages/account/BookingDetail'));
const MembershipWallet = p(() => import('@/pages/account/MembershipWallet'));
const Trips = p(() => import('@/pages/account/Trips'));
const Saved = p(() => import('@/pages/account/Saved'));
const Payments = p(() => import('@/pages/account/Payments'));
const Notifications = p(() => import('@/pages/account/Notifications'));
const Profile = p(() => import('@/pages/account/Profile'));

// Admin
const AdminDashboard = p(() => import('@/pages/admin/AdminDashboard'));
const AdminHotels = p(() => import('@/pages/admin/AdminHotels'));
const HotelEditor = p(() => import('@/pages/admin/HotelEditor'));
const AdminDestinations = p(() => import('@/pages/admin/AdminDestinations'));
const AdminTourPackages = p(() => import('@/pages/admin/AdminTourPackages'));
const TourPackageEditor = p(() => import('@/pages/admin/TourPackageEditor'));
const AdminCatalogue = p(() => import('@/pages/admin/AdminCatalogue'));
const AdminPlans = p(() => import('@/pages/admin/AdminPlans'));
const AdminMemberships = p(() => import('@/pages/admin/AdminMemberships'));
const AdminBookings = p(() => import('@/pages/admin/AdminBookings'));
const AdminBookingDetail = p(() => import('@/pages/admin/AdminBookingDetail'));
const AdminPayments = p(() => import('@/pages/admin/AdminPayments'));
const AdminUsers = p(() => import('@/pages/admin/AdminUsers'));
const AdminUserDetail = p(() => import('@/pages/admin/AdminUserDetail'));
const AdminCoupons = p(() => import('@/pages/admin/AdminCoupons'));
const AdminReviews = p(() => import('@/pages/admin/AdminReviews'));
const AdminContent = p(() => import('@/pages/admin/AdminContent'));
const AdminRoles = p(() => import('@/pages/admin/AdminRoles'));
const AdminSettings = p(() => import('@/pages/admin/AdminSettings'));
const AdminAuditLogs = p(() => import('@/pages/admin/AdminAuditLogs'));
const AdminReports = p(() => import('@/pages/admin/AdminReports'));

// Partner
const PartnerOverview = p(() => import('@/pages/partner/PartnerOverview'));

export const router = createBrowserRouter([
  {
    element: <PublicLayout />,
    errorElement: <RouteError />,
    children: [
      { path: '/', element: <Home />, handle: { transparentHeader: true } },
      { path: '/hotels', element: <HotelSearch /> },
      { path: '/hotels/:slug', element: <HotelDetails /> },
      { path: '/tours', element: <Tours /> },
      { path: '/tours/:slug', element: <TourDetails /> },
      { path: '/tour-builder', element: <TourBuilderLanding /> },
      { path: '/tour-builder/:id', element: <RequireAuth><TripEditor /></RequireAuth> },
      { path: '/trip/:token', element: <SharedTrip /> },
      { path: '/membership', element: <Membership /> },
      { path: '/destinations', element: <Destinations /> },
      { path: '/destinations/:slug', element: <DestinationDetail /> },
      { path: '/offers', element: <Offers /> },
      { path: '/about', element: <About /> },
      { path: '/contact', element: <Contact /> },
      { path: '/faq', element: <Faq /> },
      { path: '/login', element: <GuestOnly><Login /></GuestOnly> },
      { path: '/register', element: <GuestOnly><Register /></GuestOnly> },
      { path: '/forgot-password', element: <ForgotPassword /> },
      { path: '/reset-password', element: <ResetPassword /> },
      { path: '/verify-email', element: <VerifyEmail /> },
      { path: '/checkout/hotel', element: <RequireAuth><HotelCheckout /></RequireAuth> },
      { path: '/checkout/trip/:id', element: <RequireAuth><TripCheckout /></RequireAuth> },
      { path: '/checkout/membership/:planId', element: <RequireAuth><MembershipCheckout /></RequireAuth> },
      { path: '/checkout/sandbox/:reference', element: <RequireAuth><SandboxCheckout /></RequireAuth> },
      { path: '/checkout/status/:reference', element: <RequireAuth><PaymentStatus /></RequireAuth> },
      { path: '/payments/return', element: <RequireAuth><PaymentStatus /></RequireAuth> },
      {
        path: '/account',
        element: <AccountLayout />,
        children: [
          { index: true, element: <Dashboard /> },
          { path: 'bookings', element: <Bookings /> },
          { path: 'bookings/:number', element: <BookingDetail /> },
          { path: 'membership', element: <MembershipWallet /> },
          { path: 'trips', element: <Trips /> },
          { path: 'saved', element: <Saved /> },
          { path: 'payments', element: <Payments /> },
          { path: 'notifications', element: <Notifications /> },
          { path: 'profile', element: <Profile /> },
        ],
      },
      { path: '*', element: <NotFound /> },
    ],
  },
  {
    path: '/admin',
    element: <AdminLayout />,
    errorElement: <RouteError />,
    children: [
      { index: true, element: <AdminDashboard /> },
      { path: 'hotels', element: <AdminHotels /> },
      { path: 'hotels/new', element: <HotelEditor /> },
      { path: 'hotels/:id', element: <HotelEditor /> },
      { path: 'destinations', element: <AdminDestinations /> },
      { path: 'tour-packages', element: <AdminTourPackages /> },
      { path: 'tour-packages/new', element: <TourPackageEditor /> },
      { path: 'tour-packages/:id', element: <TourPackageEditor /> },
      { path: 'catalogue', element: <AdminCatalogue /> },
      { path: 'membership-plans', element: <AdminPlans /> },
      { path: 'memberships', element: <AdminMemberships /> },
      { path: 'bookings', element: <AdminBookings /> },
      { path: 'bookings/:id', element: <AdminBookingDetail /> },
      { path: 'payments', element: <AdminPayments /> },
      { path: 'users', element: <AdminUsers /> },
      { path: 'users/:id', element: <AdminUserDetail /> },
      { path: 'coupons', element: <AdminCoupons /> },
      { path: 'reviews', element: <AdminReviews /> },
      { path: 'content', element: <AdminContent /> },
      { path: 'roles', element: <AdminRoles /> },
      { path: 'settings', element: <AdminSettings /> },
      { path: 'audit-logs', element: <AdminAuditLogs /> },
      { path: 'reports', element: <AdminReports /> },
    ],
  },
  {
    path: '/partner',
    element: <PartnerLayout />,
    errorElement: <RouteError />,
    children: [
      { index: true, element: <PartnerOverview /> },
      { path: 'hotels/:id', element: <HotelEditor partner /> },
      { path: 'bookings', element: <AdminBookings partner /> },
      { path: 'bookings/:id', element: <AdminBookingDetail partner /> },
    ],
  },
]);
