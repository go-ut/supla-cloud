<?php
/*
 Copyright (C) AC SOFTWARE SP. Z O.O.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.
 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.
 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace SuplaBundle\Controller\Api;

use Assert\Assertion;
use Doctrine\ORM\EntityManagerInterface;
use FOS\RestBundle\Controller\Annotations as Rest;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use SuplaBundle\Auth\Voter\AccessIdSecurityVoter;
use SuplaBundle\Entity\IODevice;
use SuplaBundle\EventListener\UnavailableInMaintenance;
use SuplaBundle\Exception\ApiException;
use SuplaBundle\Model\ApiVersions;
use SuplaBundle\Model\Dependencies\ChannelDependencies;
use SuplaBundle\Model\Schedule\ScheduleManager;
use SuplaBundle\Model\Transactional;
use SuplaBundle\Repository\IODeviceChannelRepository;
use SuplaBundle\Repository\IODeviceRepository;
use SuplaBundle\Supla\SuplaServerAware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class IODeviceController extends RestController {
    use SuplaServerAware;
    use Transactional;

    /** @var ScheduleManager */
    private $scheduleManager;
    /** @var IODeviceChannelRepository */
    private $iodeviceRepository;

    public function __construct(ScheduleManager $scheduleManager, IODeviceRepository $iodeviceRepository) {
        $this->scheduleManager = $scheduleManager;
        $this->iodeviceRepository = $iodeviceRepository;
    }

    protected function getDefaultAllowedSerializationGroups(Request $request): array {
        $groups = [
            'channels', 'location', 'originalLocation', 'connected', 'accessids', 'state',
            'channels' => 'iodevice.channels',
            'location' => 'iodevice.location',
            'originalLocation' => 'iodevice.originalLocation',
            'accessids' => 'location.accessids',
        ];
        if (!ApiVersions::V2_4()->isRequestedEqualOrGreaterThan($request)) {
            $groups[] = 'schedules';
            $groups['schedules'] = 'iodevice.schedules';
        }
        return $groups;
    }

    /** @Security("has_role('ROLE_IODEVICES_R')") */
    public function getIodevicesAction(Request $request) {
        $result = [];
        $user = $this->getUser();
        if (ApiVersions::V2_2()->isRequestedEqualOrGreaterThan($request)) {
            $result = $this->iodeviceRepository->findAllForUser($this->getUser());
            $result = $result->filter(
                function (IODevice $device) {
                    return $this->isGranted(AccessIdSecurityVoter::PERMISSION_NAME, $device);
                }
            );
            $result = $result->getValues();
        } else {
            if ($user !== null) {
                foreach ($user->getIODevices() as $device) {
                    $channels = [];
                    foreach ($device->getChannels() as $channel) {
                        $channels[] = [
                            'id' => $channel->getId(),
                            'chnnel_number' => $channel->getChannelNumber(),
                            'caption' => $channel->getCaption(),
                            'type' => [
                                'name' => 'TYPE_' . $channel->getType()->getName(),
                                'id' => $channel->getType()->getId(),
                            ],
                            'function' => [
                                'name' => 'FNC_' . $channel->getFunction()->getName(),
                                'id' => $channel->getFunction()->getId(),
                            ],
                        ];
                    }
                    $result[] = [
                        'id' => $device->getId(),
                        'location_id' => $device->getLocation()->getId(),
                        'enabled' => $device->getEnabled(),
                        'name' => $device->getName(),
                        'comment' => $device->getComment(),
                        'registration' => [
                            'date' => $device->getRegDate()->getTimestamp(),
                            'ip_v4' => $device->getRegIpv4(),
                        ],
                        'last_connected' => [
                            'date' => $device->getLastConnected()->getTimestamp(),
                            'ip_v4' => $device->getLastIpv4(),
                        ],
                        'guid' => $device->getGUIDString(),
                        'software_version' => $device->getSoftwareVersion(),
                        'protocol_version' => $device->getProtocolVersion(),
                        'channels' => $channels,
                    ];
                }
            }
            $result = ['iodevices' => $result];
        }
        $view = $this->serializedView($result, $request);
        if (ApiVersions::V2_3()->isRequestedEqualOrGreaterThan($request)) {
            $view->setHeader('X-Total-Count', count($result));
        }
        return $view;
    }

    /**
     * @Security("ioDevice.belongsToUser(user) and has_role('ROLE_IODEVICES_R') and is_granted('accessIdContains', ioDevice)")
     * @Rest\Get("/iodevices/{ioDevice}", requirements={"ioDevice"="^\d+$"})
     */
    public function getIodeviceAction(Request $request, IODevice $ioDevice) {
        if (ApiVersions::V2_2()->isRequestedEqualOrGreaterThan($request)) {
            $result = $ioDevice;
        } else {
            $enabled = $ioDevice->getEnabled();
            $connected = $this->suplaServer->isDeviceConnected($ioDevice);

            $channels = [];

            foreach ($ioDevice->getChannels() as $channel) {
                $channels[] = [
                    'id' => $channel->getId(),
                    'chnnel_number' => $channel->getChannelNumber(),
                    'caption' => $channel->getCaption(),
                    'type' => [
                        'name' => 'TYPE_' . $channel->getType()->getName(),
                        'id' => $channel->getType()->getId(),
                    ],
                    'function' => [
                        'name' => 'FNC_' . $channel->getFunction()->getName(),
                        'id' => $channel->getFunction()->getId(),
                    ],
                ];
            }

            $result[] = [
                'id' => $ioDevice->getId(),
                'location_id' => $ioDevice->getLocation()->getId(),
                'enabled' => $enabled,
                'connected' => $connected,
                'name' => $ioDevice->getName(),
                'comment' => $ioDevice->getComment(),
                'registration' => [
                    'date' => $ioDevice->getRegDate()->getTimestamp(),
                    'ip_v4' => $ioDevice->getRegIpv4(),
                ],
                'last_connected' => [
                    'date' => $ioDevice->getLastConnected()->getTimestamp(),
                    'ip_v4' => $ioDevice->getLastIpv4(),
                ],
                'guid' => $ioDevice->getGUIDString(),
                'software_version' => $ioDevice->getSoftwareVersion(),
                'protocol_version' => $ioDevice->getProtocolVersion(),
                'channels' => $channels,
            ];
        }
        return $this->serializedView($result, $request, ['location.relationsCount', 'iodevice.relationsCount']);
    }

    /**
     * @Security("has_role('ROLE_IODEVICES_R')")
     * @Rest\Get("/iodevices/{guid}")
     */
    public function getIodeviceByGuidAction(Request $request, string $guid, IODeviceRepository $repository) {
        $ioDevice = $repository->findForUserByGuid($this->getUser(), $guid);
        $this->denyAccessUnlessGranted('accessIdContains', $ioDevice);
        return $this->getIodeviceAction($request, $ioDevice);
    }

    /**
     * @Security("ioDevice.belongsToUser(user) and has_role('ROLE_IODEVICES_RW') and is_granted('accessIdContains', ioDevice)")
     * @UnavailableInMaintenance
     */
    public function putIodeviceAction(
        Request $request,
        IODevice $ioDevice,
        IODevice $updatedDevice,
        ChannelDependencies $channelDependencies
    ) {
        $result = $this->transactional(function (EntityManagerInterface $em) use (
            $channelDependencies,
            $request,
            $ioDevice,
            $updatedDevice
        ) {
            $enabledChanged = $ioDevice->getEnabled() != $updatedDevice->getEnabled();
            if ($enabledChanged) {
                $shouldAsk = ApiVersions::V2_4()->isRequestedEqualOrGreaterThan($request)
                    ? $request->get('safe', false)
                    : !$request->get('confirm', false);
                if (!$updatedDevice->getEnabled() && $shouldAsk) {
                    $dependencies = [];
                    foreach ($ioDevice->getChannels() as $channel) {
                        $dependencies = array_merge_recursive($dependencies, $channelDependencies->getDependencies($channel));
                    }
                    if (count(array_filter($dependencies))) {
                        $view = $this->view($dependencies, Response::HTTP_CONFLICT);
                        $this->setSerializationGroups($view, $request, ['scene'], ['scene']);
                        return $view;
                    }
                }
                $ioDevice->setEnabled($updatedDevice->getEnabled());
                if (!$ioDevice->getEnabled()) {
                    $this->scheduleManager->disableSchedulesForDevice($ioDevice);
                }
            }
            if ($updatedDevice->getLocation()->getId()) {
                $ioDevice->setLocation($updatedDevice->getLocation());
            }
            $ioDevice->setComment($updatedDevice->getComment());
            return $this->serializedView($ioDevice, $request, ['iodevice.schedules']);
        });
        $this->suplaServer->onDeviceSettingsChanged($ioDevice);
        $this->suplaServer->reconnect();
        return $result;
    }

    /**
     * @Security("ioDevice.belongsToUser(user) and has_role('ROLE_IODEVICES_RW') and is_granted('accessIdContains', ioDevice)")
     * @UnavailableInMaintenance
     */
    public function patchIodeviceAction(Request $request, IODevice $ioDevice) {
        $body = json_decode($request->getContent(), true);
        Assertion::keyExists($body, 'action', 'Missing action.');
        $device = $this->transactional(function (EntityManagerInterface $em) use ($body, $ioDevice) {
            $action = $body['action'];
            if ($action === 'enterConfigurationMode') {
                Assertion::true(
                    $ioDevice->isEnterConfigurationModeAvailable(),
                    'Entering configuration mode is unsupported in the firmware.' // i18n
                );
                $result = $this->suplaServer->deviceAction($ioDevice, 'ENTER-CONFIGURATION-MODE');
                Assertion::true($result, 'Could not enter the configuration mode.'); // i18n
            } else {
                throw new ApiException('Invalid action given.');
            }
            $em->persist($ioDevice);
            return $ioDevice;
        });
        return $this->getIodeviceAction($request, $device->clearRelationsCount());
    }

    /**
     * @Security("ioDevice.belongsToUser(user) and has_role('ROLE_IODEVICES_RW') and is_granted('accessIdContains', ioDevice)")
     * @UnavailableInMaintenance
     */
    public function deleteIodeviceAction(IODevice $ioDevice, Request $request, ChannelDependencies $channelDependencies) {
        $deviceId = $ioDevice->getId();
        if ($request->get('safe', false)) {
            $dependencies = [];
            foreach ($ioDevice->getChannels() as $channel) {
                $dependencies = array_merge_recursive($dependencies, $channelDependencies->getDependencies($channel));
            }
            if (count(array_filter($dependencies))) {
                $view = $this->view($dependencies, Response::HTTP_CONFLICT);
                $this->setSerializationGroups($view, $request, ['scene'], ['scene']);
                return $view;
            }
        }
        $cannotDeleteMsg = 'Cannot delete this I/O Device right now.'; // i18n
        Assertion::true($this->suplaServer->userAction('BEFORE-DEVICE-DELETE', $ioDevice->getId()), $cannotDeleteMsg);
        $this->transactional(function (EntityManagerInterface $em) use ($channelDependencies, $ioDevice) {
            foreach ($ioDevice->getChannels() as $channel) {
                $channelDependencies->clearDependencies($channel);
            }
            foreach ($ioDevice->getChannels() as $channel) {
                $em->remove($channel);
            }
            $em->remove($ioDevice);
        });
        $this->suplaServer->reconnect();
        $this->suplaServer->userAction('ON-DEVICE-DELETED', $deviceId);
        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
