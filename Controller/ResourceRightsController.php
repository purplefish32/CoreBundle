<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Claroline\CoreBundle\Controller;

use Claroline\CoreBundle\Entity\Role;
use Claroline\CoreBundle\Entity\Resource\ResourceNode;
use Claroline\CoreBundle\Entity\Resource\ResourceType;
use Claroline\CoreBundle\Entity\User;
use Claroline\CoreBundle\Library\Resource\ResourceCollection;
use Claroline\CoreBundle\Manager\WorkspaceTagManager;
use Claroline\CoreBundle\Manager\RightsManager;
use Claroline\CoreBundle\Manager\RoleManager;
use Claroline\CoreBundle\Manager\MaskManager;
use Claroline\CoreBundle\Persistence\ObjectManager;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\SecurityContext;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as EXT;
use JMS\DiExtraBundle\Annotation as DI;
use Symfony\Component\HttpFoundation\File\Exception\AccessDeniedException;

class ResourceRightsController
{
    private $rightsManager;
    private $maskManager;
    private $request;
    private $sc;
    private $wsTagManager;
    private $templating;
    private $roleManager;
    private $om;

    /**
     * @DI\InjectParams({
     *     "rightsManager" = @DI\Inject("claroline.manager.rights_manager"),
     *     "maskManager"   = @DI\Inject("claroline.manager.mask_manager"),
     *     "requestStack"  = @DI\Inject("request_stack"),
     *     "sc"            = @DI\Inject("security.context"),
     *     "wsTagManager"  = @DI\Inject("claroline.manager.workspace_tag_manager"),
     *     "templating"    = @DI\Inject("templating"),
     *     "roleManager"   = @DI\Inject("claroline.manager.role_manager"),
     *     "om"            = @DI\Inject("claroline.persistence.object_manager")
     * })
     */
    public function __construct(
        RightsManager $rightsManager,
        MaskManager $maskManager,
        RequestStack $requestStack,
        SecurityContext $sc,
        WorkspaceTagManager $wsTagManager,
        TwigEngine $templating,
        RoleManager $roleManager,
        ObjectManager $om
    )
    {
        $this->rightsManager = $rightsManager;
        $this->request = $requestStack;
        $this->sc = $sc;
        $this->wsTagManager = $wsTagManager;
        $this->templating = $templating;
        $this->roleManager = $roleManager;
        $this->maskManager = $maskManager;
        $this->om = $om;
    }

    /**
     * @EXT\Route(
     *     "/{node}/rights/form/role/{role}",
     *     name="claro_resource_right_form",
     *     options={"expose"=true},
     *     defaults={"role"=null}
     * )
     *
     * Displays the resource rights form.
     *
     * @param ResourceNode $node
     * @param \Claroline\CoreBundle\Entity\Role $role
     *
     * @return Response
     */
    public function rightFormAction(ResourceNode $node, Role $role = null)
    {
        $collection = new ResourceCollection(array($node));
        $this->checkAccess('EDIT', $collection);
        $isDir = $node->getResourceType()->getName() === 'directory';

        if (!$role) {
            $data = $this->wsTagManager->getDatasForWorkspaceList(true);
            $rolesRights = $this->rightsManager->getConfigurableRights($node);
            $mask = $this->maskManager->decodeMask(
                $node->getResourceType()->getDefaultMask(), $node->getResourceType()
            );

            $data['resourceRights'] = $rolesRights;
            $data['resource'] = $node;
            $data['isDir'] = $isDir;
            $data['isModal'] = true;
            $data['mask'] = $mask;

            return $this->templating->renderResponse(
                'ClarolineCoreBundle:Resource:multipleRightsPage.html.twig',
                $data
            );
        } else {
            $resourceRights = $this->rightsManager->getOneByRoleAndResource($role, $node);

            return $this->templating->renderResponse(
                'ClarolineCoreBundle:Resource:singleRightsForm.html.twig',
                array(
                    'resourceRights' => $resourceRights,
                    'isDir' => $isDir,
                    'role' => $role,
                    'node' => $node
                )
            );
        }
    }

    /**
     * @EXT\Route(
     *     "/{node}/rights/edit",
     *     name="claro_resource_rights_edit",
     *     options={"expose"=true}
     * )
     *
     * Handles the submission of the resource multiple rights form. Expects an array of permissions
     * by role to be passed by POST method. Permissions are set to false when not passed
     * in the request.
     *
     * @param ResourceNode $node the resource
     *
     * @return Response
     *
     * @throws AccessDeniedException if the current user is not allowed to edit the resource
     */

    public function editPermsAction(ResourceNode $node)
    {
        $collection = new ResourceCollection(array($node));
        $this->checkAccess('EDIT', $collection);
        $datas = $this->getPermissionsFromRequest($node->getResourceType());
        $isRecursive = $this->request->getCurrentRequest()->request->get('isRecursive');

        foreach ($datas as $data) {
            $this->rightsManager->editPerms($data['permissions'], $data['role'], $node, $isRecursive);
        }

        return new Response("success");
    }

    /**
     * @EXT\Route(
     *     "/{node}/role/{role}/right/creation/form",
     *     name="claro_resource_right_creation_form",
     *     options={"expose"=true}
     * )
     * @EXT\Template("ClarolineCoreBundle:Resource:rightsCreation.html.twig")
     *
     * Displays the form for resource creation rights (i.e the right to create a
     * type of resource in a directory). Show the different resource types already
     * allowed for creation.
     *
     * @param ResourceNode $node the resource
     * @param Role         $role the role for which the form is displayed
     *
     * @return Response
     *
     * @throws AccessDeniedException if the current user is not allowed to edit the resource
     */
    public function rightCreationFormAction(ResourceNode $node, Role $role)
    {
        $collection = new ResourceCollection(array($node));
        $this->checkAccess('EDIT', $collection);

        return array(
            'configs' => array($this->rightsManager->getOneByRoleAndResource($role, $node)),
            'resourceTypes' => $this->rightsManager->getResourceTypes(),
            'nodeId' => $node->getId(),
            'roleId' => $role->getId()
        );
    }

    /**
     * @EXT\Route(
     *     "/{node}/role/{role}/right/creation/edit",
     *     name="claro_resource_rights_creation_edit",
     *     options={"expose"=true}
     * )
     *
     * Handles the submission of the resource rights creation form. Expects an
     * array of resource type ids to be passed by POST method. Only the types
     * passed in the request will be allowed.
     *
     * @param ResourceNode $node the resource
     * @param Role         $role the role for which the form is displayed
     *
     * @return Response
     *
     * @throws AccessDeniedException if the current user is not allowed to edit the resource
     */
    public function editPermsCreationAction(ResourceNode $node, Role $role)
    {
        $collection = new ResourceCollection(array($node));
        $this->checkAccess('EDIT', $collection);
        $isRecursive = $this->request->getCurrentRequest()->request->get('isRecursive');
        $ids = $this->request->getCurrentRequest()->request->get('resourceTypes');
        $resourceTypes = $ids === null ?
            array() :
            $this->om->findByIds('ClarolineCoreBundle:Resource\ResourceType', array_keys($ids));
        $this->rightsManager->editCreationRights($resourceTypes, $role, $node, $isRecursive);

        return new Response("success");
    }

    public function getPermissionsFromRequest(ResourceType $type)
    {
        $permsMap = $this->maskManager->getPermissionMap($type);
        $roles = $this->request->getCurrentRequest()->request->get('roles');
        $rows = $this->request->getCurrentRequest()->request->get('role_row');
        $data = array();

        foreach (array_keys($rows) as $roleId) {
            if (!array_key_exists($roleId, $roles)) {
                foreach ($permsMap as $perm) {
                    $changedPerms[$perm] = false;
                }

                $data[] = array(
                    'role' => $this->roleManager->getRole($roleId),
                    'permissions' => $changedPerms
                );
            }
        }

        foreach ($roles as $roleId => $perms) {

            foreach ($permsMap as $perm) {
                $changedPerms[$perm] = (array_key_exists($perm, $perms)) ? true: false;
            }

            $data[] = array(
                'role' => $this->roleManager->getRole($roleId),
                'permissions' => $changedPerms
            );
        }

        return $data;
    }

    /**
     * Checks if the current user has the right to perform an action on a ResourceCollection.
     * Be careful, ResourceCollection may need some aditionnal parameters.
     *
     * - for CREATE: $collection->setAttributes(array('type' => $resourceType))
     *  where $resourceType is the name of the resource type.
     * - for MOVE / COPY $collection->setAttributes(array('parent' => $parent))
     *  where $parent is the new parent entity.
     *
     * @param  string                $permission
     * @param  ResourceCollection    $collection
     * @throws AccessDeniedException if the current user is not allowed to edit the resource
     */
    private function checkAccess($permission, ResourceCollection $collection)
    {
        if (!$this->sc->isGranted($permission, $collection)) {
            throw new AccessDeniedException($collection->getErrorsForDisplay());
        }
    }
}
