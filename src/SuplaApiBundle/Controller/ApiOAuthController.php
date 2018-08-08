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

namespace SuplaApiBundle\Controller;

use Assert\Assertion;
use Doctrine\ORM\EntityManagerInterface;
use FOS\OAuthServerBundle\Model\ClientManagerInterface;
use FOS\RestBundle\Controller\Annotations as Rest;
use OAuth2\OAuth2;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use SuplaApiBundle\Auth\OAuthScope;
use SuplaApiBundle\Auth\SuplaOAuth2;
use SuplaApiBundle\Entity\OAuth\AccessToken;
use SuplaApiBundle\Entity\OAuth\ApiClient;
use SuplaApiBundle\Entity\OAuth\ApiClientAuthorization;
use SuplaBundle\Model\Transactional;
use SuplaBundle\Repository\AccessTokenRepository;
use SuplaBundle\Supla\SuplaServerAware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiOAuthController extends RestController {
    use Transactional;
    use SuplaServerAware;

    /** @var AccessTokenRepository */
    private $accessTokenRepository;
    /** @var SuplaOAuth2 */
    private $oauthServer;
    /** @var ClientManagerInterface */
    private $clientManager;

    public function __construct(
        AccessTokenRepository $accessTokenRepository,
        SuplaOAuth2 $oauthServer,
        ClientManagerInterface $clientManager
    ) {
        $this->accessTokenRepository = $accessTokenRepository;
        $this->oauthServer = $oauthServer;
        $this->clientManager = $clientManager;
    }

    /**
     * @Security("has_role('ROLE_WEBAPP')")
     * @Rest\Get("/oauth-clients")
     */
    public function getOAuthClientsAction(Request $request) {
        $applications = $this->getUser()->getApiClients();
        $view = $this->view($applications, Response::HTTP_OK);
        $this->setSerializationGroups($view, $request, []);
        return $view;
    }

    /**
     * @Security("client.belongsToUser(user) and has_role('ROLE_WEBAPP')")
     * @Rest\Get("/oauth-clients/{client}")
     */
    public function getOAuthClientAction(ApiClient $client, Request $request) {
        $view = $this->view($client, Response::HTTP_OK);
        $this->setSerializationGroups($view, $request, ['secret']);
        return $view;
    }

    /**
     * @Security("has_role('ROLE_WEBAPP')")
     * @Rest\Post("/oauth-clients")
     */
    public function postOAuthClientsAction(ApiClient $newClient, Request $request) {
        $newClient->setAllowedGrantTypes([OAuth2::GRANT_TYPE_AUTH_CODE]);
        $newClient->setUser($this->getUser());
        $this->clientManager->updateClient($newClient);
        return $this->getApplicationAction($newClient, $request);
    }

    /**
     * @Security("client.belongsToUser(user) && has_role('ROLE_WEBAPP')")
     * @Rest\Put("/oauth-clients/{client}")
     */
    public function putOAuthClientAction(ApiClient $client, ApiClient $updatedClient, Request $request) {
        $client->setName($updatedClient->getName());
        $client->setDescription($updatedClient->getDescription());
        $client->setRedirectUris($updatedClient->getRedirectUris());
        $this->clientManager->updateClient($client);
        return $this->getApplicationAction($client, $request);
    }

    /**
     * @Security("client.belongsToUser(user) and has_role('ROLE_WEBAPP')")
     * @Rest\Delete("/oauth-clients/{client}")
     */
    public function deleteOAuthClientAction(ApiClient $client) {
        return $this->transactional(function (EntityManagerInterface $em) use ($client) {
            $em->remove($client);
            return new Response('', Response::HTTP_NO_CONTENT);
        });
    }

    /**
     * @Rest\Get("/oauth-authorized-clients")
     * @Security("has_role('ROLE_WEBAPP')")
     */
    public function getAuthorizedClientsAction(Request $request) {
        $apps = $this->getUser()->getApiClientAuthorizations();
        $view = $this->view($apps, Response::HTTP_OK);
        $this->setSerializationGroups($view, $request, ['client']);
        return $view;
    }

    /**
     * @Rest\Delete("/oauth-authorized-clients/{authorizedApp}")
     * @Security("authorizedApp.belongsToUser(user) and has_role('ROLE_WEBAPP')")
     */
    public function deleteAuthorizedClientsAction(ApiClientAuthorization $authorizedApp, Request $request) {
        return $this->transactional(function (EntityManagerInterface $em) use ($authorizedApp) {
            $em->remove($authorizedApp);
            return new Response('', Response::HTTP_NO_CONTENT);
        });
    }

    /**
     * @Rest\Get("/oauth-personal-tokens")
     * @Security("has_role('ROLE_WEBAPP')")
     */
    public function getPersonalTokensAction(Request $request) {
        $accessTokens = $this->accessTokenRepository->findPersonalTokens($this->getUser());
        $view = $this->view($accessTokens, Response::HTTP_OK);
        $this->setSerializationGroups($view, $request, []);
        return $view;
    }

    /**
     * @Rest\Post("/oauth-personal-tokens")
     * @Security("has_role('ROLE_WEBAPP')")
     */
    public function postPersonalTokensAction(Request $request) {
        $data = $request->request->all();
        Assertion::keyExists($data, 'name');
        Assertion::keyExists($data, 'scope');
        Assertion::notBlank($data['name'], 'Personal token name is required.');
        $scope = new OAuthScope($data['scope']);
        $token = $this->transactional(function (EntityManagerInterface $entityManager) use ($data, $scope) {
            $token = $this->oauthServer->createPersonalAccessToken($this->getUser(), $data['name'], $scope);
            $entityManager->persist($token);
            return $token;
        });
        $view = $this->view($token, Response::HTTP_CREATED);
        $this->setSerializationGroups($view, $request, ['token'], ['token']);
        return $view;
    }

    /**
     * @Rest\Delete("/oauth-personal-tokens/{accessToken}")
     * @Security("accessToken.belongsToUser(user) and accessToken.isPersonal() and has_role('ROLE_WEBAPP')")
     */
    public function deletePersonalTokenAction(AccessToken $accessToken, Request $request) {
        return $this->transactional(function (EntityManagerInterface $em) use ($accessToken) {
            $em->remove($accessToken);
            return new Response('', Response::HTTP_NO_CONTENT);
        });
    }
}
