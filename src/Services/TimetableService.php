<?php

namespace FlexkitTen\Services;

class TimetableService
{
    private MindbodyAPI $mindbodyApi;
    private Logger $logger;

    public function __construct(MindbodyAPI $mindbodyApi, Logger $logger)
    {
        $this->mindbodyApi = $mindbodyApi;
        $this->logger = $logger;
    }

    private function debugLog(string $message, array $context = []): void
    {
        $this->logger->logTimetableOperation($message, $context);
    }

    public function getLocations(): array
    {
        $this->debugLog("Fetching locations for timetable filter");

        try {
            $response = $this->mindbodyApi->makeRequest(
                '/site/locations',
                [
                    'limit' => 100,
                    'offset' => 0
                ],
                'GET'
            );

            if (!isset($response['Locations'])) {
                throw new \Exception('Invalid response format');
            }

            $locations = array_map(function ($location) {
                if (in_array($location['Id'], [7, 10], true)) {
                    $this->debugLog("Excluding location ID: " . $location['Id']);
                    return null;
                }

                if (!$location['HasClasses']) {
                    return null;
                }

                return [
                    'id' => $location['Id'],
                    'name' => $location['Name'],
                    'siteId' => $location['SiteID']
                ];
            }, $response['Locations']);

            $locations = array_filter($locations);

            $this->debugLog("Successfully fetched locations", [
                'count' => count($locations),
                'excluded_ids' => [7, 10]
            ]);

            return array_values($locations);

        } catch (\Exception $e) {
            $this->logger->error("Error fetching locations: " . $e->getMessage());
            throw $e;
        }
    }

    public function getPrograms(string $scheduleType = 'Class'): array
    {
        $this->debugLog("Fetching programs for timetable filter", ['type' => $scheduleType]);

        try {
            $response = $this->mindbodyApi->makeRequest(
                '/site/programs',
                [
                    'limit' => 100,
                    'offset' => 0,
                    'scheduleType' => $scheduleType
                ],
                'GET'
            );

            if (!isset($response['Programs'])) {
                throw new \Exception('Invalid response format');
            }

            $programs = array_map(function ($program) use ($scheduleType) {
                if ($scheduleType === 'Class' && $program['Id'] !== 22) {
                    return null;
                }

                return [
                    'id' => $program['Id'],
                    'name' => $program['Name']
                ];
            }, $response['Programs']);

            $programs = array_filter($programs);

            $this->debugLog("Successfully fetched programs", ['count' => count($programs)]);
            return array_values($programs);

        } catch (\Exception $e) {
            $this->logger->error("Error fetching programs: " . $e->getMessage());
            throw $e;
        }
    }

    public function getSessionTypes(bool $onlineOnly = true): array
    {
        $this->debugLog("Fetching session types for timetable filter", [
            'online_only' => $onlineOnly
        ]);

        try {
            $response = $this->mindbodyApi->getSessionTypes([
                'onlineOnly' => $onlineOnly
            ]);

            if (!isset($response['SessionTypes'])) {
                throw new \Exception('Invalid response format');
            }

            $sessionTypes = array_map(function ($sessionType) {
                return [
                    'id' => $sessionType['Id'],
                    'name' => $sessionType['Name']
                ];
            }, $response['SessionTypes']);

            $this->debugLog("Successfully fetched session types", [
                'count' => count($sessionTypes),
                'online_only' => $onlineOnly
            ]);

            return $sessionTypes;

        } catch (\Exception $e) {
            $this->logger->error("Error fetching session types: " . $e->getMessage());
            throw $e;
        }
    }

    public function getFilterOptions(bool $onlineOnly = true): array
    {
        try {
            $locations = $this->getLocations();
            $classPrograms = $this->getPrograms('Class');
            $appointmentPrograms = $this->getPrograms('Appointment');
            $sessionTypes = $this->getSessionTypes($onlineOnly);

            return [
                'locations' => $locations,
                'programs' => [
                    'classes' => $classPrograms,
                    'appointments' => $appointmentPrograms
                ],
                'sessionTypes' => $sessionTypes
            ];
        } catch (\Exception $e) {
            $this->logger->error("Error getting filter options: " . $e->getMessage());
            throw $e;
        }
    }

    public function getTimetableData(array $filters): array
    {
        $this->debugLog("Getting timetable data", ['filters' => $filters]);

        try {
            $scheduleType = $filters['scheduleType'] ?? 'Class';

            if ($scheduleType === 'Class') {
                return $this->getClassSchedule($filters);
            } else {
                return $this->getAppointmentSchedule($filters);
            }
        } catch (\Exception $e) {
            $this->logger->error("Error getting timetable data: " . $e->getMessage());
            throw $e;
        }
    }

    private function getClassSchedule(array $params): array
    {
        $this->debugLog("Fetching class schedule");

        $mindbodyParams = [
            'startDateTime' => $params['startDate'] ?? date('c'),
            'endDateTime' => $params['endDate'] ?? date('c', strtotime('+30 days')),
            'locationIds' => $params['locationIds'] ?? [],
            'programIds' => $params['programIds'] ?? [],
            'sessionTypeIds' => $params['sessionTypeIds'] ?? []
        ];

        $response = $this->mindbodyApi->getClassSchedule($mindbodyParams);

        return $response;
    }

    private function generateTimeSlots(array $availability): array
    {
        $timeSlots = [];

        foreach ($availability as $slot) {
            if (isset($slot['StartDateTime']) && isset($slot['EndDateTime'])) {
                $start = new \DateTime($slot['StartDateTime']);
                $end = new \DateTime($slot['EndDateTime']);

                while ($start < $end) {
                    $slotEnd = clone $start;
                    $slotEnd->add(new \DateInterval('PT30M'));

                    if ($slotEnd <= $end) {
                        $timeSlots[] = [
                            'start' => $start->format('c'),
                            'end' => $slotEnd->format('c'),
                            'available' => true
                        ];
                    }

                    $start->add(new \DateInterval('PT30M'));
                }
            }
        }

        return $timeSlots;
    }

    private function getAppointmentSchedule(array $params): array
    {
        $this->debugLog("Fetching appointment schedule");

        $mindbodyParams = [
            'startDateTime' => $params['startDate'] ?? date('c'),
            'endDateTime' => $params['endDate'] ?? date('c', strtotime('+30 days')),
            'locationIds' => $params['locationIds'] ?? [],
            'staffIds' => $params['staffIds'] ?? [],
            'sessionTypeIds' => $params['sessionTypeIds'] ?? []
        ];

        $response = $this->mindbodyApi->makeRequest(
            '/appointment/appointmenttimes',
            $mindbodyParams
        );

        if (isset($response['AppointmentTimes'])) {
            $timeSlots = $this->generateTimeSlots($response['AppointmentTimes']);
            $response['TimeSlots'] = $timeSlots;
        }

        return $response;
    }

    public function bookClass(int $clientId, int $classId, bool $sendEmail = true): array
    {
        $this->debugLog("Booking class", [
            'client_id' => $clientId,
            'class_id' => $classId,
            'send_email' => $sendEmail
        ]);

        try {
            $response = $this->mindbodyApi->addClientToClass($clientId, $classId);

            $this->debugLog("Class booking successful", [
                'client_id' => $clientId,
                'class_id' => $classId
            ]);

            return [
                'success' => true,
                'message' => 'Class booked successfully',
                'booking_data' => $response
            ];

        } catch (\Exception $e) {
            $this->logger->error("Class booking failed: " . $e->getMessage(), [
                'client_id' => $clientId,
                'class_id' => $classId
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function cancelClass(int $clientId, int $classId): array
    {
        $this->debugLog("Cancelling class", [
            'client_id' => $clientId,
            'class_id' => $classId
        ]);

        try {
            $response = $this->mindbodyApi->makeRequest(
                '/class/removeclientfromclass',
                [
                    'clientId' => $clientId,
                    'classId' => $classId
                ],
                'POST'
            );

            $this->debugLog("Class cancellation successful", [
                'client_id' => $clientId,
                'class_id' => $classId
            ]);

            return [
                'success' => true,
                'message' => 'Class cancelled successfully',
                'cancellation_data' => $response
            ];

        } catch (\Exception $e) {
            $this->logger->error("Class cancellation failed: " . $e->getMessage(), [
                'client_id' => $clientId,
                'class_id' => $classId
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function bookAppointment(int $clientId, array $appointmentData): array
    {
        $this->debugLog("Booking appointment", [
            'client_id' => $clientId,
            'appointment_data' => $appointmentData
        ]);

        try {
            $sessionTypeId = $appointmentData['sessionTypeId'] ?? null;
            $this->debugLog("Checking client services for session type", [
                'client_id' => $clientId,
                'session_type_id' => $sessionTypeId
            ]);
            $services = $this->mindbodyApi->getClientServices($clientId, $sessionTypeId);
            
            if (empty($services['ClientServices'])) {
                $this->logger->error("No valid services found for client", [
                    'client_id' => $clientId,
                    'session_type_id' => $sessionTypeId
                ]);

                $allServices = $this->mindbodyApi->getClientServices($clientId);
                
                if (empty($allServices['ClientServices'])) {
                    return [
                        'success' => false,
                        'code' => 'no_active_services',
                        'message' => 'You don\'t have any active packages or memberships. Please purchase a package to book appointments.',
                        'redirect' => '/pricing'
                    ];
                } else {
                    return [
                        'success' => false,
                        'code' => 'invalid_service_type',
                        'message' => 'Your current package does not allow booking this type of appointment. Please purchase an appropriate package.',
                        'redirect' => '/pricing'
                    ];
                }
            }

            $validService = false;
            foreach ($services['ClientServices'] as $service) {
                if ($service['Remaining'] > 0 && strtotime($service['ExpirationDate']) > time()) {
                    $this->debugLog("Found valid service", [
                        'service_id' => $service['Id'],
                        'remaining' => $service['Remaining'],
                        'expiration' => $service['ExpirationDate']
                    ]);
                    $validService = true;
                    break;
                } else {
                    $this->debugLog("Invalid service found", [
                        'service_id' => $service['Id'],
                        'remaining' => $service['Remaining'],
                        'expiration' => $service['ExpirationDate']
                    ]);
                }
            }

            if (!$validService) {
                $this->logger->error("No valid services with remaining sessions found", [
                    'client_id' => $clientId
                ]);
                
                return [
                    'success' => false,
                    'code' => 'no_remaining_sessions',
                    'message' => 'You have no remaining sessions in your package. Please purchase additional sessions to book appointments.',
                    'redirect' => '/pricing'
                ];
            }

            $bookingParams = [
                'ApplyPayment' => false,
                'ClientId' => $clientId,
                'LocationId' => $appointmentData['locationId'],
                'SessionTypeId' => $appointmentData['sessionTypeId'],
                'StaffId' => $appointmentData['staffId'],
                'StaffRequested' => true,
                'StartDateTime' => $appointmentData['startDateTime'],
                'Test' => false
            ];

            if (!empty($appointmentData['notes'])) {
                $bookingParams['Notes'] = $appointmentData['notes'];
            }

            $this->debugLog("Sending appointment booking request", [
                'booking_params' => $bookingParams
            ]);

            $response = $this->mindbodyApi->makeRequest(
                '/appointment/addappointment',
                $bookingParams,
                'POST',
                true
            );

            $this->debugLog("Appointment booking successful", [
                'client_id' => $clientId
            ]);

            return [
                'success' => true,
                'message' => 'Appointment booked successfully',
                'booking_data' => $response
            ];

        } catch (\Exception $e) {
            $this->logger->error("Appointment booking failed: " . $e->getMessage(), [
                'client_id' => $clientId
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function cancelAppointment(int $appointmentId, bool $sendEmail = true, bool $lateCancel = false): array
    {
        $this->debugLog("Cancelling appointment", [
            'appointment_id' => $appointmentId,
            'send_email' => $sendEmail,
            'late_cancel' => $lateCancel
        ]);

        try {
            $requestData = [
                'appointmentId' => $appointmentId,
                'status' => 'Cancelled',
                'sendEmail' => $sendEmail,
                'lateCancel' => $lateCancel
            ];

            $response = $this->mindbodyApi->makeRequest(
                '/appointment/updateappointment',
                $requestData,
                'POST'
            );

            $this->debugLog("Appointment cancellation successful", [
                'appointment_id' => $appointmentId,
                'send_email' => $sendEmail,
                'late_cancel' => $lateCancel
            ]);

            return [
                'success' => true,
                'message' => 'Appointment cancelled successfully',
                'cancellation_data' => $response
            ];

        } catch (\Exception $e) {
            $this->logger->error("Appointment cancellation failed: " . $e->getMessage(), [
                'appointment_id' => $appointmentId,
                'send_email' => $sendEmail,
                'late_cancel' => $lateCancel
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}